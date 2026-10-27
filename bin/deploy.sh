#!/bin/sh
# Деплой темы на WordPress-сервер: rsync + сброс кэша WP. Запуск с рабочей машины.
# Целевой сервер настраивается тремя переменными ниже; прод-сайты с другими
# копиями темы не задеваются — скрипт обновляет ровно один каталог WP_PATH.
HOST=dtpstat					# ssh-алиас или адрес сервера
WP_PATH='wp/wp-content/themes/dtpstat-child'	# каталог темы на сервере, от домашней папки
WP_CLI='php ~/wp-cli.phar --path=$HOME/wp'	# вызов wp-cli на сервере (~ и $HOME раскроются там же)
set -e
cd "$(dirname "$0")/.."
# Перед деплоем сгенерируйте на целевом сервере WP_CONTENT_DIR/dtpstat-config.php
# из проектного .env с помощью bin/generate-config.py (см. DEPLOY.md).
# Этот файл вне каталога темы, rsync его не обновляет и не удаляет.
rsync -az --delete --exclude='.git' -e "ssh -o BatchMode=yes" theme/dtpstat-child/ "$HOST:$WP_PATH/"
ssh -o BatchMode=yes "$HOST" "$WP_CLI cache flush >/dev/null 2>&1 || true"
echo "Тема обновлена: $HOST:$WP_PATH"
