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
# Страхуемся: не меняем PHP-тему, если в целевом WordPress нет конфига.
# WP_PATH = <WP_CONTENT_DIR>/themes/dtpstat-child; конфиг лежит в WP_CONTENT_DIR.
WP_CONTENT_PATH=$(dirname "$(dirname "$WP_PATH")")
if ! ssh -o BatchMode=yes "$HOST" "test -r '$WP_CONTENT_PATH/dtpstat-config.php'"; then
  echo "ОШИБКА: на $HOST нет читаемого $WP_CONTENT_PATH/dtpstat-config.php" >&2
  echo "Сначала сгенерируйте его из проектного .env; см. DEPLOY.md" >&2
  exit 1
fi
rsync -az --delete --exclude='.git' -e "ssh -o BatchMode=yes" theme/dtpstat-child/ "$HOST:$WP_PATH/"
ssh -o BatchMode=yes "$HOST" "$WP_CLI cache flush >/dev/null 2>&1 || true"
echo "Тема обновлена: $HOST:$WP_PATH"
