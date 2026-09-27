#!/usr/bin/env bash
# Проверка результата накатки против эталона из content/settings.json.
#
#   bin/verify.sh [URL] [--external --wp "..."]   (URL по умолчанию http://localhost:8086)
set -euo pipefail
cd "$(dirname "$0")/.."

URL=http://localhost:8086
WP_CMD="wp"
while [[ $# -gt 0 ]]; do
  case "$1" in
    --external) WP_CMD=${2:-wp}; shift 2;;
    *) URL=$1; shift;;
  esac
done
# в stand-режиме wp-cli через compose, URL проверяем снаружи curl'ом
if [[ $WP_CMD == "wp" && -f compose.yml ]] && docker compose ps -q wp 2>/dev/null | grep -q .; then
  wp() { docker compose run --rm wpcli wp "$@" --allow-root 2>/dev/null; }
else
  wp() { $WP_CMD "$@"; }
fi

eval "$(python3 - <<'PY'
import json
d = json.load(open("content/settings.json"))
c = d["counts"]
print(f"WANT_PAGES={c['pages']} WANT_DRAFTS={c['drafts']} WANT_POSTS={c['posts']}"
      f" WANT_PERSONS={c['persons']} WANT_SPONSORS={c['sponsors']} WANT_MEDIA={c['media']}")
print("WANT_CATS=" + str(len(d["categories"])))
print("SOURCE_URL=" + repr(d["source_url"]))
print("FRONT_SLUG=" + repr(d["pages"]["front"]))
print("NEWS_SLUG=" + repr(d["pages"]["posts"]))
print("PERMALINK=" + repr(d["options"]["permalink_structure"]))
print("PPP=" + str(d["options"]["posts_per_page"]))
print("ICON_FILE=" + repr(d["site_icon"]["file"]))
PY
)"

FAIL=0
check() { # имя, получено, ожидалось
  if [[ "$2" == "$3" ]]; then echo "   ok   $1"; else echo "   FAIL $1: получено «$2», ожидалось «$3»"; FAIL=1; fi
}

echo ">> счётчики"
check "страниц (publish)"  "$(wp post list --post_type=page --post_status=publish --format=count)" "$WANT_PAGES"
check "страниц (draft)"    "$(wp post list --post_type=page --post_status=draft --format=count)"   "$WANT_DRAFTS"
check "постов"             "$(wp post list --post_type=post --post_status=publish --format=count)" "$WANT_POSTS"
check "dtp_person"         "$(wp post list --post_type=dtp_person --format=count)"                 "$WANT_PERSONS"
check "dtp_sponsor"        "$(wp post list --post_type=dtp_sponsor --format=count)"                "$WANT_SPONSORS"
check "медиа"              "$(wp post list --post_type=attachment --format=count)"                 "$WANT_MEDIA"

echo ">> кропы crop-thumbnails (главный сигнал, что media не перегенерированы)"
# эталон: в скольких аттачментах дамп содержит ручной кроп
EXPECT_CROPS=$(python3 -c '
import json
d = json.load(open("content/attachments.json"))
print(sum(1 for it in d if "cpt_last_cropping_data" in json.dumps(it["meta"])))')
GOT_CROPS=$(wp eval 'global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = \"_wp_attachment_metadata\" AND meta_value LIKE \"%cpt_last_cropping_data%\"");')
check "аттачментов с ручным кропом" "$GOT_CROPS" "$EXPECT_CROPS"

echo ">> настройки"
check "front = $FRONT_SLUG"  "$(wp post get "$(wp option get page_on_front)" --field=post_name 2>/dev/null)" "$FRONT_SLUG"
check "posts page = $NEWS_SLUG" "$(wp post get "$(wp option get page_for_posts)" --field=post_name 2>/dev/null)" "$NEWS_SLUG"
check "permalink"  "$(wp option get permalink_structure)" "$PERMALINK"
check "posts_per_page" "$(wp option get posts_per_page)" "$PPP"
check "категорий"  "$(wp term list category --format=count)" "$WANT_CATS"
check "тег vacancy" "$(wp term list post_tag --slug=vacancy --format=count 2>/dev/null || echo 0)" "1"

echo ">> меню"
check "primary назначено" "$(wp menu location list --format=csv 2>/dev/null | grep -c '^primary,')" "1"
check "footer назначено"  "$(wp menu location list --format=csv 2>/dev/null | grep -c '^footer,')"  "1"

echo ">> остатки исходного URL (контент и guid должны быть переписаны)"
check "упоминаний $SOURCE_URL в записях" \
  "$(wp db query "SELECT COUNT(*) FROM $(wp db prefix)posts WHERE post_content LIKE '%$SOURCE_URL%' OR guid LIKE '%$SOURCE_URL%'" --skip-column-names 2>/dev/null | tr -d '[:space:]')" "0"

echo ">> HTTP ($URL)"
http_code() { curl -s -o /dev/null -w '%{http_code}' "$1"; }
check "GET /"                 "$(http_code "$URL/")" "200"
# фронт-страница перенаправляет на свой permalink — 301 это норма
FCODE=$(http_code "$URL/$FRONT_SLUG/")
check "GET /$FRONT_SLUG/ (редирект на /)" "$([[ $FCODE == 200 || $FCODE == 301 ]] && echo ok)" "ok"
check "GET /download/"        "$(http_code "$URL/download/")" "200"
check "GET /support-project/" "$(http_code "$URL/support-project/")" "200"
check "GET /project/"         "$(http_code "$URL/project/")" "200"
check "GET /join-team/"       "$(http_code "$URL/join-team/")" "200"
check "шрифт Roboto"          "$(http_code "$URL/wp-content/themes/dtpstat-child/assets/fonts/Roboto-Regular.woff2")" "200"
check "медиа из uploads"      "$(http_code "$URL/wp-content/uploads/$ICON_FILE")" "200"

echo ">> маркеры вёрстки"
body() { curl -s "$1"; }
ge() { # имя, получено, минимум
  if [[ $2 -ge $3 ]] 2>/dev/null; then echo "   ok   $1 ($2)"; else echo "   FAIL $1: получено «$2», минимум $3"; FAIL=1; fi
}
ge "главная: карта (.dtp-map)"       "$(body "$URL/" | grep -c 'dtp-map' || true)" 1
ge "download: поиск (.js-dl-search)" "$(body "$URL/download/" | grep -c 'js-dl-search' || true)" 1
ge "support-project: [dtp_fund_bar]" "$(body "$URL/support-project/" | grep -c 'dtp-fund__fill' || true)" 1
ge "support-project: промо-SVG"      "$(body "$URL/support-project/" | grep -c 'promo/tribute-logo.svg' || true)" 1
ge "project: карточки команды"       "$(body "$URL/project/" | grep -o 'class="dtp-person' | wc -l)" "$WANT_PERSONS"
ge "join-team: карточки вакансий"    "$(body "$URL/join-team/" | grep -o 'class="dtp-job' | wc -l)" 3

if [[ $FAIL == 0 ]]; then echo; echo "Все проверки прошли."; else echo; echo "Есть расхождения — см. FAIL выше."; exit 1; fi
