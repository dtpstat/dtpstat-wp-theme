#!/bin/sh
# Деплой темы на ilia.dtp-stat.ru (новый сайт; прод wp.dtp-stat.ru не трогаем).
# Запуск с рабочей машины: rsync темы + сброс кэша WP.
# Настройки окружения (DTP_FRONTEND_URL и др.) живут на сервере в /home/ilia/.env —
# rsync их не касается; текущая тема активна, переключение не требуется.
set -e
cd "$(dirname "$0")/.."
rsync -az --delete --exclude='.git' -e "ssh -o BatchMode=yes" theme/dtpstat-child/ dtpstat:wp/wp-content/themes/dtpstat-child/
ssh -o BatchMode=yes dtpstat 'php ~/wp-cli.phar --path=/home/ilia/wp cache flush >/dev/null 2>&1 || true'
echo "Тема обновлена: https://ilia.dtp-stat.ru"
