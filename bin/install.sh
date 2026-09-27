#!/usr/bin/env bash
# Накатка пакета на чистый WordPress.
#
#   bin/install.sh                                  — свой стенд compose (порт ${WP_PORT:-8086})
#   bin/install.sh --external --url https://site   — на уже доступный WP (нужен wp-cli на хосте,
#                                                     или своя команда через --wp "wp --path=...")
#
# Порядок: ядро (ru_RU) → neve + тема → плагины (пины версий) → uploads целиком
# (перегенерация сбросила бы ручные кропы crop-thumbnails) → медиа-записи
# (исходные id, в обход WXR — bin/attachments.php) → WXR-импорт контента →
# search-replace URL → настройки/меню (WXR их не переносит) → реврайты.
set -euo pipefail
cd "$(dirname "$0")/.."

URL=http://localhost:8086
MODE=stand
FORCE=""

ADMIN_USER=ilya
ADMIN_PASS=${ADMIN_PASSWORD:-wplocal}
ADMIN_EMAIL=local@dtp-stat.ru
WP_CMD="wp"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --url) URL=$2; shift 2;;
    --external) MODE=external; shift;;
    --admin-pass) ADMIN_PASS=$2; shift 2;;
    --wp) WP_CMD=$2; shift 2;;
    --force) FORCE=1; shift;;
    *) echo "неизвестный аргумент: $1" >&2; exit 1;;
  esac
done

if [[ $MODE == stand ]]; then
  # xml лежит в /pkg (маунт пакета в сервисе wpcli)
  wp() { docker compose run --rm wpcli wp "$@" --allow-root; }
  XML_PATH=/pkg/content/export.xml
else
  wp() { $WP_CMD "$@"; }
  XML_PATH=content/export.xml
fi

# --- параметры из settings.json (repr-литералы совместимы с bash для этих значений)
eval "$(python3 - <<'PY'
import json
d = json.load(open("content/settings.json"))
print("SOURCE_URL=" + repr(d["source_url"]))
print("BLOGNAME=" + repr(d["blogname"]))
print("BLOGDESC=" + repr(d["blogdescription"]))
print("FRONT_SLUG=" + repr(d["pages"]["front"]))
print("NEWS_SLUG=" + repr(d["pages"]["posts"]))
print("PRIVACY_SLUG=" + repr(d["pages"]["privacy"]))
print("PERMALINK=" + repr(d["options"]["permalink_structure"]))
print("PPP=" + str(d["options"]["posts_per_page"]))
print("DATEFMT=" + repr(d["options"]["date_format"]))
print("TIMEFMT=" + repr(d["options"]["time_format"]))
print("WEEK=" + str(d["options"]["start_of_week"]))
print("ICON_FILE=" + repr(d["site_icon"]["file"]))
print("LOGO_FILE=" + repr(d["site_logo"]["file"]))
print("NEVE_VER=" + repr(next(t["version"] for t in d["themes"] if t["name"] == "neve")))
for loc, m in d["menus"].items():
    key = "PRIMARY" if loc == "primary" else "FOOTER"
    print(f"MENU_{key}_TITLES=(" + " ".join(repr(i["title"]) for i in m["items"]) + ")")
    print(f"MENU_{key}_PAGES=(" + " ".join(repr(i["page"]) for i in m["items"]) + ")")
for c in d["categories"]:
    print(f"CATS+=({repr(c['name'] + ' ' + c['slug'])})")
PY
)"

page_id() { # id страницы по слагу
  wp post list --post_type=page --name="$1" --post_status=any --field=ID
}

# --- стенд
if [[ $MODE == stand ]]; then
  echo ">> стенд compose (${WP_PORT:-8086})"
  # без --wait: сервис wpcli одноразовый и выходит сразу — --wait счёл бы это провалом;
  # готовность db каждый wp-вызов гарантирует depends_on (service_healthy)
  docker compose up -d
fi

# --- ядро
if ! wp core is-installed 2>/dev/null; then
  echo ">> ядро"
  wp core install --url="$URL" --title="$BLOGNAME" \
    --admin_user="$ADMIN_USER" --admin_password="$ADMIN_PASS" \
    --admin_email="$ADMIN_EMAIL" --skip-email
  wp language core install ru_RU --activate >/dev/null 2>&1 || echo "   ! ru_RU не поставился (не критично)"
  # сток от core install: «Hello World», «Sample Page» и черновик «Privacy Policy» —
  # в песочнице их нет, свой privacy приедет из WXR. --name ищет только в post
  # (дефолт), поэтому --post_type=any; --post_status=any — иначе не виден черновик
  for stock_slug in hello-world sample-page privacy-policy; do
    # 'any' не включает черновики (exclude_from_search) — статус нужен явным списком
    STOCK_ID=$(wp post list --name="$stock_slug" --post_type=any \
      --post_status=publish,draft,private,future,pending --field=ID 2>/dev/null)
    [[ -n $STOCK_ID ]] && wp post delete "$STOCK_ID" --force >/dev/null
  done
fi

# защита от повторного импорта на непустую цель: search-replace меняет контент,
# post_exists импортёра перестаёт узнавать записи — плодятся дубликаты
EXISTING=$(wp post list --post_type=page,post,dtp_person,dtp_sponsor --format=count 2>/dev/null || echo 0)
if [[ $EXISTING != 0 && -z $FORCE ]]; then
  echo "ОШИБКА: на цели уже есть контент ($EXISTING записей). Повторная накатка наделает дубликатов." >&2
  echo "  стенд пакета:        docker compose down -v && bin/install.sh" >&2
  echo "  осознанный повтор:   bin/install.sh --force" >&2
  exit 1
fi

# --- тема и плагины
echo ">> тема neve $NEVE_VER + dtpstat-child"
wp theme install neve --version="$NEVE_VER"
if [[ $MODE == external ]]; then
  # на своём стенде тема примаунчена из репозитория; на внешний целим — копируем
  THEME_PATH=$(wp theme path 2>/dev/null) || THEME_PATH=""
  if [[ -n $THEME_PATH && ! -d "$THEME_PATH/dtpstat-child" ]]; then
    cp -r theme/dtpstat-child "$THEME_PATH/dtpstat-child"
  fi
fi
wp theme activate dtpstat-child

echo ">> плагины (версии из settings.json)"
wp plugin install wordpress-importer --activate >/dev/null
i=0
for spec in crop-thumbnails otter-blocks post-types-order templates-patterns-collection; do
  ver=$(python3 -c "import json;d=json.load(open('content/settings.json'));print(next(p['version'] for p in d['plugins'] if p['name']=='$spec'))")
  wp plugin install "$spec" --version="$ver" --activate >/dev/null && echo "   $spec $ver"
done

# --- медиафайлы
echo ">> uploads целиком (с кропами)"
if [[ $MODE == stand ]]; then
  docker compose exec wp mkdir -p /var/www/html/wp-content/uploads
  docker compose cp content/uploads/. wp:/var/www/html/wp-content/uploads/
  docker compose exec wp chown -R 33:33 /var/www/html/wp-content/uploads
else
  UPLOADS_DIR=$(wp eval 'echo WP_CONTENT_DIR . "/uploads";')
  rsync -a content/uploads/ "$UPLOADS_DIR"/ && echo "   rsync в $UPLOADS_DIR"
fi

# --- контент
# Порядок важен: записи медиа создаём ДО WXR-импорта с исходными id (import_id).
# Тогда валидны ссылки в контенте (wp-image-N, {"id":N} в блоках, _thumbnail_id),
# а страницы/посты получают новые id выше диапазона медиа — без коллизий.
echo ">> медиа-записи (исходные id + meta с ручными кропами)"
if [[ $MODE == stand ]]; then
  wp eval-file /pkg/bin/attachments.php restore /pkg/content/attachments.json
else
  wp eval-file bin/attachments.php restore content/attachments.json
fi

echo ">> WXR-импорт (страницы/посты/CPT; аттачменты вырезаны — созданы выше)"
# Аттачменты вырезаем из WXR построчно (без XML-перекодировки), иначе импортёр
# напечатает "Failed to import" на каждый: fetch у него безусловный (bin/attachments.php).
IMPORT_XML=content/.import.xml
python3 - "$IMPORT_XML" <<'PY'
import sys
out, buf = [], None
for line in open("content/export.xml", encoding="utf-8"):
    st = line.strip()
    if st.startswith("<item"):
        buf = [line]; continue
    if buf is None:
        out.append(line); continue
    buf.append(line)
    if st == "</item>":
        if "attachment</wp:post_type>" not in "".join(buf):
            out += buf
        buf = None
open(sys.argv[1], "w", encoding="utf-8").write("".join(out))
PY
if [[ $MODE == stand ]]; then
  IMPORT_XML=/pkg/content/.import.xml   # пакет виден в контейнере по /pkg
fi
wp import "$IMPORT_XML" --authors=create --user=1
rm -f content/.import.xml

echo ">> URL: $SOURCE_URL → $URL"
# одной заменой, guid тоже переписываем (самодостаточное зеркало, не миграция домена);
# содержимое постов импортёр уже перезаписал при импорте (url-mapping) — замены там 0
wp search-replace "$SOURCE_URL" "$URL" --all-tables --report-changed-only

# --- настройки (WXR их не переносит)
echo ">> настройки"
FRONT_ID=$(page_id "$FRONT_SLUG"); NEWS_ID=$(page_id "$NEWS_SLUG"); PRIVACY_ID=$(page_id "$PRIVACY_SLUG")
[[ -n $FRONT_ID ]] && wp option update show_on_front page >/dev/null && wp option update page_on_front "$FRONT_ID" >/dev/null
[[ -n $NEWS_ID ]] && wp option update page_for_posts "$NEWS_ID" >/dev/null
[[ -n $PRIVACY_ID ]] && wp option update wp_page_for_privacy_policy "$PRIVACY_ID" >/dev/null
wp option update permalink_structure "$PERMALINK" >/dev/null
wp option update posts_per_page "$PPP" >/dev/null
wp option update blogname "$BLOGNAME" >/dev/null
[[ -n $BLOGDESC ]] && wp option update blogdescription "$BLOGDESC" >/dev/null
wp option update date_format "$DATEFMT" >/dev/null
wp option update time_format "$TIMEFMT" >/dev/null
wp option update start_of_week "$WEEK" >/dev/null
ICON_ID=$(wp post list --post_type=attachment --meta_key=_wp_attached_file --meta_value="$ICON_FILE" --field=ID)
LOGO_ID=$(wp post list --post_type=attachment --meta_key=_wp_attached_file --meta_value="$LOGO_FILE" --field=ID)
[[ -n $ICON_ID ]] && wp option update site_icon "$ICON_ID" >/dev/null && echo "   site_icon ← $ICON_FILE"
[[ -n $LOGO_ID ]] && wp option update site_logo "$LOGO_ID" >/dev/null

# --- категории: пустые не переносятся WXR, досоздаём
echo ">> категории"
for entry in "${CATS[@]}"; do
  name=${entry% *}; slug=${entry##* }
  # slug может быть percent-encoded (кириллица); смотрим факт по терму-слагу
  if [[ $(wp term list category --slug="$slug" --format=count 2>/dev/null || echo 0) == 0 ]]; then
    wp term create category "$name" --slug="$slug" --quiet && echo "   + $name"
  fi
done

# --- меню (WXR их не переносит)
echo ">> меню"
make_menu() { # $1=location $2=titles-array $3=pages-array $4=имя меню
  local LOC=$1 T_ARR=$2 P_ARR=$3 NAME=$4 MID i
  wp menu delete "$NAME" >/dev/null 2>&1 || true
  MID=$(wp menu create "$NAME" --porcelain)
  local T_REF=${T_ARR}[@] P_REF=${P_ARR}[@]
  local TITLES=("${!T_REF}") PAGES=("${!P_REF}")
  for i in "${!PAGES[@]}"; do
    local PID; PID=$(page_id "${PAGES[$i]}")
    wp menu item add-post "$MID" "$PID" --title="${TITLES[$i]}" --porcelain >/dev/null
  done
  wp menu location assign "$MID" "$LOC" >/dev/null
  echo "   $NAME → $LOC (${#PAGES[@]} пункта)"
}
make_menu primary MENU_PRIMARY_TITLES MENU_PRIMARY_PAGES "Главное меню"
make_menu footer MENU_FOOTER_TITLES MENU_FOOTER_PAGES "Меню футера"

# --- финал
echo ">> реврайты"
wp rewrite flush --hard >/dev/null 2>&1 || wp rewrite flush >/dev/null
wp plugin deactivate wordpress-importer >/dev/null 2>&1 || true
wp plugin delete wordpress-importer >/dev/null 2>&1 || true

echo
echo "Готово: $URL (админ $ADMIN_USER / пароль из --admin-pass или ADMIN_PASSWORD, по умолчанию wplocal)"
echo "Проверка: bin/verify.sh $URL"
