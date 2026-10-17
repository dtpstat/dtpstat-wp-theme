#!/usr/bin/env bash
# Адрес фронтенда карты для айфреймов темы (dtp_pages_url()) на стенде пакета:
#   bin/frontend-url.sh http://localhost:8180   — задать
#   bin/frontend-url.sh --default               — вернуть дефолт темы
# Пишет .env в данные стенда: тема читает его на каждом запросе, перезапуск
# стенда не нужен. Env-переменная стенда DTP_FRONTEND_URL, если задана,
# приоритетнее файла.
set -euo pipefail
cd "$(dirname "$0")/.."
case ${1:?использование: bin/frontend-url.sh <URL>|--default} in
  --default)
    docker compose exec wp rm -f /var/www/html/.env
    ;;
  *)
    docker compose exec -T wp sh -c 'printf "DTP_FRONTEND_URL=%s\n" "$1" > /var/www/html/.env' sh "$1"
    ;;
esac
