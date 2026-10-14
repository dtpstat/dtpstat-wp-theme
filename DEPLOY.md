# Выкатка

Запуск с рабочей машины:

```sh
./bin/deploy.sh
```

rsync `theme/dtpstat-child/` на сервер `dtpstat` в `wp/wp-content/themes/dtpstat-child/`
(сайт <https://ilia.dtp-stat.ru>) + `wp cache flush`. Тема уже активна — переключать ничего
не нужно. Прод `wp.dtp-stat.ru` (`/var/www/wp`) скрипт не трогает. Требование: ssh-алиас `dtpstat`.

Настройки окружения живут на сервере в `/home/ilia/.env` — rsync их не касается.

## Окружение (`/home/ilia/.env`, читается в рантайме через `dtp_env()`)

- `DTP_FRONTEND_URL` — адрес фронтенда для айфреймов карточек `/dtp/<id>/`.
  Смена = правка одной строки, пересборка темы не нужна.

## Айфрейм карты на главной

Зашит в контент WP-страницы (`front-page.php` выводит `the_content()` осознанно), на
`DTP_FRONTEND_URL` не реагирует. При смене адреса фронтенда правится в БД:

```sh
php ~/wp-cli.phar --path=/home/ilia/wp search-replace 'https://старый' 'https://новый' --dry-run
php ~/wp-cli.phar --path=/home/ilia/wp search-replace 'https://старый' 'https://новый'
```

wp-cli на сервере: `php ~/wp-cli.phar`. БД одна на два сайта — замена по префиксу `dtp_`
(новый сайт) не задевает прод (`wp_`); сначала смотреть `--dry-run`.

## Установка с нуля и проверка

`bin/install.sh` и `bin/verify.sh` — запускать НА сервере (через ssh ломается `wp eval`),
см. README.
