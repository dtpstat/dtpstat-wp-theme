#!/usr/bin/env bash
# Снимает состояние рабочей песочницы (контейнер local-wp-1, порт 8080) в content/ пакета:
#   content/export.xml    — WXR: страницы, посты, CPT (команда/спонсоры), wp_block, медиа
#   content/uploads/      — wp-content/uploads целиком (со всеми сгенерированными размерами:
#                           перегенерация сбросила бы ручные кропы crop-thumbnails)
#   content/settings.json — настройки, категории, меню, версии, эталонные счётчики
#
# WXR не переносит меню и пустые термы — их восстанавливает bin/install.sh по settings.json.
#
# Использование: bin/export.sh [wp-контейнер]   (по умолчанию local-wp-1)
set -euo pipefail
cd "$(dirname "$0")/.."

WP_CONTAINER=${1:-local-wp-1}
CLI_IMAGE=wordpress:cli-php8.3
# Меню (nav_menu_item) и ревизии WXR не везёт в принципе — остальные типы перечисляем явно.
POST_TYPES=page,post,dtp_person,dtp_sponsor,wp_block,attachment

NET=$(docker inspect "$WP_CONTAINER" --format '{{range $k,$_ := .NetworkSettings.Networks}}{{$k}}{{end}}')
VOL=$(docker inspect "$WP_CONTAINER" --format '{{range .Mounts}}{{if eq .Type "volume"}}{{.Name}}{{end}}{{end}}')
DB_HOST=$(docker exec "$WP_CONTAINER" printenv WORDPRESS_DB_HOST)
DB_USER=$(docker exec "$WP_CONTAINER" printenv WORDPRESS_DB_USER)
DB_PASS=$(docker exec "$WP_CONTAINER" printenv WORDPRESS_DB_PASSWORD)
DB_NAME=$(docker exec "$WP_CONTAINER" printenv WORDPRESS_DB_NAME)

wp() {
  # одноразовый wp-cli-контейнер; ретраи — docker run под нагрузкой бывает флакует.
  # Пакет примаунчен /pkg:ro — eval-file bin/attachments.php видит скрипт
  local attempt out rc=1
  for attempt in 1 2 3; do
    if out=$(docker run --rm --network "$NET" -v "$VOL":/var/www/html -v "$(pwd)":/pkg:ro --user 33:33 \
             -e WORDPRESS_DB_HOST="$DB_HOST" -e WORDPRESS_DB_USER="$DB_USER" \
             -e WORDPRESS_DB_PASSWORD="$DB_PASS" -e WORDPRESS_DB_NAME="$DB_NAME" \
             "$CLI_IMAGE" wp "$@" --allow-root 2>/dev/null); then
      printf '%s\n' "$out"; return 0
    fi
    rc=$?
    echo "   ! попытка $attempt не удалась ($*), повтор..." >&2
    sleep 2
  done
  return "$rc"
}

# как wp(), но пустой вывод считается сбоем — для списков, из которых собираем данные
wp_nonempty() {
  local attempt out
  for attempt in 1 2 3; do
    out=$(wp "$@") && [[ -n $out ]] && { printf '%s\n' "$out"; return 0; }
    sleep 2
  done
  echo "   ! пустой результат: $*" >&2
  return 1
}

echo ">> источник: контейнер $WP_CONTAINER (сеть $NET, volume $VOL)"

echo ">> WXR-экспорт контента"
docker run --rm --user 0:0 -v "$VOL":/v "$CLI_IMAGE" \
  sh -c 'mkdir -p /v/wp-content/dtp-export && chown 33:33 /v/wp-content/dtp-export'
wp export --dir=/var/www/html/wp-content/dtp-export \
  --post_type="$POST_TYPES" --skip_comments \
  --filename_format=export.xml >/dev/null

# файлы из volume копируем на хост от имени хостового пользователя, затем чистим volume
docker run --rm --user "$(id -u):$(id -g)" -v "$VOL":/from:ro -v "$(pwd)":/to "$CLI_IMAGE" \
  sh -c 'cp /from/wp-content/dtp-export/export.xml /to/content/export.xml'
docker run --rm --user 0:0 -v "$VOL":/v "$CLI_IMAGE" sh -c 'rm -rf /v/wp-content/dtp-export'

echo ">> uploads целиком"
docker run --rm --user "$(id -u):$(id -g)" -v "$VOL":/from:ro -v "$(pwd)":/to "$CLI_IMAGE" \
  sh -c 'rm -rf /to/content/uploads && cp -R /from/wp-content/uploads /to/content/uploads && rm -rf /to/content/uploads/tmp'

echo ">> attachments.json (записи медиа со всем meta; WXR для аттачментов не годится —"
echo "   импортёр перекачивает файлы и затирает ручные кропы, см. bin/attachments.php)"
wp eval-file /pkg/bin/attachments.php dump > content/attachments.json

echo ">> settings.json"
SRC_URL=$(wp option get siteurl)
# карта id -> slug для страниц (по ней резолвятся пункты меню)
PAGES_CSV=$(wp post list --post_type=page --post_status=any --fields=ID,post_name,post_title --format=csv)
# аттачменты для site_icon/site_logo — по стабильному _wp_attached_file, а не по id
ICON_FILE=$(wp post meta get "$(wp option get site_icon)" _wp_attached_file)
LOGO_FILE=$(wp post meta get "$(wp option get site_logo)" _wp_attached_file)
CATS_CSV=$(wp term list category --format=csv --fields=term_id,name,slug,count)
MENUS_JSON=$(wp menu list --format=json --fields=term_id,name,slug,locations)
MENU_MAIN_CSV=$(wp_nonempty menu item list web-agency-gb-main-menu --format=csv --fields=db_id,title,object_id,type)
MENU_FOOTER_CSV=$(wp_nonempty menu item list web-agency-gb-footer-menu --format=csv --fields=db_id,title,object_id,type)
PLUGINS_JSON=$(wp plugin list --status=active --format=json --fields=name,version)
THEMES_JSON=$(wp theme list --format=json --fields=name,version,status)

# эталонные счётчики для verify.sh
C_PAGES=$(wp post list --post_type=page --post_status=publish --format=count)
C_DRAFTS=$(wp post list --post_type=page --post_status=draft --format=count)
C_POSTS=$(wp post list --post_type=post --post_status=publish --format=count)
C_PERSONS=$(wp post list --post_type=dtp_person --format=count)
C_SPONSORS=$(wp post list --post_type=dtp_sponsor --format=count)
C_MEDIA=$(wp post list --post_type=attachment --format=count)

O_BLOGNAME=$(wp option get blogname)
O_BLOGDESCRIPTION=$(wp option get blogdescription || true)
O_PERMALINK=$(wp option get permalink_structure)
O_PPP=$(wp option get posts_per_page)
O_DATEFMT=$(wp option get date_format)
O_TIMEFMT=$(wp option get time_format)
O_WEEK=$(wp option get start_of_week)

SRC_URL="$SRC_URL" PAGES_CSV="$PAGES_CSV" ICON_FILE="$ICON_FILE" LOGO_FILE="$LOGO_FILE" \
CATS_CSV="$CATS_CSV" MENUS_JSON="$MENUS_JSON" MENU_MAIN_CSV="$MENU_MAIN_CSV" \
MENU_FOOTER_CSV="$MENU_FOOTER_CSV" \
PLUGINS_JSON="$PLUGINS_JSON" THEMES_JSON="$THEMES_JSON" \
C_PAGES="$C_PAGES" C_DRAFTS="$C_DRAFTS" C_POSTS="$C_POSTS" C_PERSONS="$C_PERSONS" \
C_SPONSORS="$C_SPONSORS" C_MEDIA="$C_MEDIA" \
O_BLOGNAME="$O_BLOGNAME" O_BLOGDESCRIPTION="$O_BLOGDESCRIPTION" O_PERMALINK="$O_PERMALINK" \
O_PPP="$O_PPP" O_DATEFMT="$O_DATEFMT" O_TIMEFMT="$O_TIMEFMT" O_WEEK="$O_WEEK" \
python3 - <<'PY'
import json, csv, io, os

pages = {}
for row in csv.DictReader(io.StringIO(os.environ["PAGES_CSV"])):
    pages[row["ID"]] = row

cats = [dict(name=r["name"], slug=r["slug"], count=int(r["count"]))
        for r in csv.DictReader(io.StringIO(os.environ["CATS_CSV"]))]

# меню: пишем пункты слагами страниц — install.sh пересоздаёт меню по ним
menus_json = json.loads(os.environ["MENUS_JSON"])
items_csv = {m["slug"]: None for m in menus_json}
items_csv["web-agency-gb-main-menu"] = os.environ["MENU_MAIN_CSV"]
items_csv["web-agency-gb-footer-menu"] = os.environ["MENU_FOOTER_CSV"]
loc_map = {}
for m in menus_json:
    for loc in (m["locations"] or []):
        loc_map[loc] = m["slug"]
menus = {}
for loc, menu_slug in sorted(loc_map.items()):
    items = []
    for r in csv.DictReader(io.StringIO(items_csv[menu_slug])):
        p = pages.get(r["object_id"]) or {}
        items.append({"title": r["title"], "page": p.get("post_name", "")})
    menus[loc] = {"source_menu": menu_slug, "items": items}

privacy = next((r["post_name"] for r in pages.values() if "privacy" in r["post_name"]), "")

data = {
    "source_url": os.environ["SRC_URL"],
    "blogname": os.environ["O_BLOGNAME"],
    "blogdescription": os.environ["O_BLOGDESCRIPTION"],
    "pages": {"front": "home", "posts": "news", "privacy": privacy},
    "options": {
        "permalink_structure": os.environ["O_PERMALINK"],
        "posts_per_page": int(os.environ["O_PPP"]),
        "date_format": os.environ["O_DATEFMT"],
        "time_format": os.environ["O_TIMEFMT"],
        "start_of_week": int(os.environ["O_WEEK"]),
    },
    "site_icon": {"file": os.environ["ICON_FILE"]},
    "site_logo": {"file": os.environ["LOGO_FILE"]},
    "categories": cats,
    "menus": menus,
    "plugins": json.loads(os.environ["PLUGINS_JSON"]),
    "themes": json.loads(os.environ["THEMES_JSON"]),
    "counts": {k: int(os.environ["C_" + k.upper()]) for k in
               ("pages", "drafts", "posts", "persons", "sponsors", "media")},
}
with open("content/settings.json", "w", encoding="utf-8") as f:
    json.dump(data, f, ensure_ascii=False, indent=2)
    f.write("\n")
print(json.dumps(data["counts"], ensure_ascii=False))
PY

echo ">> готово:"
ls -la content/export.xml; du -sh content/uploads
