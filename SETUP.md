# Развёртывание DTPStat: WordPress, фронтенд карты и API

Это инструкция по **связке компонентов**. Детали, ограничения и восстановление серверной установки WordPress находятся в [INSTALLER.md](INSTALLER.md). В [README.md](README.md) — обзор пакета.

| Компонент | Репозиторий | Назначение |
|---|---|---|
| WordPress + `dtpstat-child` | [dtpstat-wp-theme](https://github.com/dtpstat/dtpstat-wp-theme) | Страницы, публикации, медиа, оформление и iframe |
| Статический фронтенд | [website-frontend-mask](https://github.com/dtpstat/website-frontend-mask) | Карта и страницы отдельных ДТП (TypeScript → `dist/`) |
| Backend API | [dtpstat-map](https://github.com/dtpstat/dtpstat-map) | Данные для карты (разворачивается самостоятельно) |

Важное различие: **iframe карты на главной** записан в контенте WordPress, а **iframe карточек ДТП** `/dtp/<id>/` строится дочерней темой. Поэтому у них два независимых адреса.

## 1. Установка пакета в уже установленный WordPress

Требуется одиночный WordPress (не multisite) с рабочими `wp-config.php` и WP-CLI, запуск непосредственно на сервере, а также Python ≥ 3.8, Bash, PHP CLI с расширениями, rsync и утилита экспорта БД. Новый `bin/install.sh` **не** устанавливает ядро WordPress, **не** поднимает Docker Compose и не поддерживает старые параметры `--external`, `--wp`, `--admin-pass`, `--reset` или `--force`.

```bash
git clone https://github.com/dtpstat/dtpstat-wp-theme.git /var/www/dtpstat-wp-theme
cd /var/www/dtpstat-wp-theme

# Проверка без изменения контента сайта:
bash bin/install.sh \
  --wp-path /var/www/wp \
  --wp-cli-phar /var/www/dtpstat-wp-theme/wp-cli.phar \
  --check
```

Команды запускать от владельца файлов WordPress. Для использования установленного `wp` в PATH вместо `--wp-cli-phar` допустим `--wp-bin /usr/local/bin/wp`. Если сайт отличается от `home`, целевой URL можно указать через `--url https://example.org`.

Для пустого по контенту WordPress после успешной проверки запустите ту же команду **без `--check`**. Если остались только штатные записи WordPress, и вы намерены их удалить, добавьте `--remove-stock-content` в обе команды.

### ВНИМАНИЕ: установка поверх существующего сайта

**`--replace-content` удаляет существующий контент и вложения**, после создания резервной копии БД и `wp-content`. Не обеспечивает автоматического отката и не сбрасывает все сторонние таблицы/настройки. Сайт не должен принимать параллельные изменения; перед началом подготовьте план ручного восстановления.

```bash
# Сначала проверка будущего режима замены:
bash bin/install.sh --wp-path /var/www/wp \
  --wp-cli-phar /var/www/dtpstat-wp-theme/wp-cli.phar \
  --replace-content --check

# После проверки и подготовки восстановления:
# повторить ТУ ЖЕ команду, убрав только --check.
```

Безопасная повторная установка поверх частичного импорта не предусмотрена. Каталоги резервных копий и журналов по умолчанию: `~/dtpstat-backups` и `~/dtpstat-install-logs`. Подробная процедура в [INSTALLER.md](INSTALLER.md).

## 2. Фронтенд карты

Сборку и её переменные необходимо сверять с [README фронтенда](https://github.com/dtpstat/website-frontend-mask#readme). Это отдельный проект; WP-установщик его не скачивает и не запускает.

```bash
git clone https://github.com/dtpstat/website-frontend-mask.git
cd website-frontend-mask
cp .env.example .env
# Указать нужные YANDEX_MAPS_KEY, MAPBOX_ACCESS_TOKEN и API_ORIGIN
npm install
npm run check
npm run build
```

`dist/` — статические HTML/CSS/JS; их можно отдавать через nginx или аналогичный сервер. `dist/maps-config.js` хранит настройки карт и `API_ORIGIN`, его можно менять отдельно от исходной сборки (см. документацию фронтенда). Если `API_ORIGIN` пуст, на хостинге должен работать same-origin прокси `/api` к запущенному бэкенду; без прокси API не появится.

`npm run serve` — вариант для разработки: локально раздаёт `dist/` и проксирует `/api`. Для production используйте выбранную схему хостинга и HTTPS. Если сайт WordPress открыт по HTTPS, iframe фронтенда тоже должен открываться по HTTPS.

## 3. Конфигурация WordPress: .env → PHP-константы

**WordPress теперь НЕ читает `.env` ни на одном HTTP-запросе.**

Источником является **`.env` корня этого репозитория**, например
`/var/www/dtpstat-wp-theme/.env`. Это конфигурация *только данного проекта*,
не общий `/var/www/.env`. Файл игнорируется Git.

Из него запускаемый вручную или установщиком генератор
`bin/generate-config.py` создаёт
`WP_CONTENT_DIR/dtpstat-config.php`, например
`/var/www/wp/wp-content/dtpstat-config.php`.
Тема в `functions.php` подключает этот PHP-файл через `require_once` и
использует только объявленные в нём константы. Поскольку конфиг расположен
**за пределами каталога темы**, `rsync --delete` темы его не удалит.
Внешние ссылки в обычном контенте не переписываются.

```bash
cd /var/www/dtpstat-wp-theme

# Один раз: скопировать пример и указать реальные адреса.
cp -n wordpress.env.example .env

# Проверка без создания/обновления PHP-файла:
python3 bin/generate-config.py \
  --output /var/www/wp/wp-content/dtpstat-config.php \
  --check

# Явная генерация после успешной проверки:
python3 bin/generate-config.py \
  --output /var/www/wp/wp-content/dtpstat-config.php

# Синтаксис и права:
php -l /var/www/wp/wp-content/dtpstat-config.php
stat -c '%U:%G %a %n' /var/www/wp/wp-content/dtpstat-config.php
```

Путь после `--output` должен соответствовать **фактическому**
`WP_CONTENT_DIR` WordPress; здесь показан пример для стандартной установки.
Генератор не требует загрузки WordPress и не меняет БД. Файл пишется
атомарной заменой и содержит только перечисленные публичные адреса и
контактные сведения; **пароли/секреты в такой конфигурации запрещены**.
Внешний доступ к `wp-content/dtpstat-config.php` без WordPress не раскрывает
константы: PHP-файл защищён проверкой `ABSPATH`. Также обеспечьте
корректную обработку PHP на веб-сервере.

Пример настройки `<repo>/.env`:

```dotenv
DTP_MAP_URL=https://map.example.org
DTP_FRONTEND_URL=https://frontend.example.org
DTP_CONTACT_EMAIL=contact@example.org
```

Используйте полный [wordpress.env.example](wordpress.env.example) для
контактного Telegram и `DTP_SOCIAL_*_URL`. Оба адреса
`DTP_MAP_URL` и `DTP_FRONTEND_URL` обязательны для генерации;
необязательные ссылки при пустом значении не выводятся.
`DTP_MAP_URL` задаёт карту главной, `DTP_FRONTEND_URL` — карточки
`/dtp/<id>/`. Для главной в WXR хранится `src="about:blank"`, а
`front-page.php` при отображении подставляет значение константы в
первый iframe. Это действует и для ранее сохранённого URL в БД.

При **изменении .env** нужно повторно выполнить генератор. Кэш HTML/CDN
может требовать сброса; при `opcache.validate_timestamps=0` необходимо
штатно обновить/инвалидировать OPcache. Ни PHP-FPM, ни WordPress
автоматически не следят за изменением `.env`.

### Старый серверный файл `/var/www/.env`

Для миграции ранее настроенного сайта сохраните его копию
в `/var/www/dtpstat-wp-theme/.env` (с правами доступа владельца),
проверьте значения, выполните `--check` и генерацию **до обновления
работающей темы**. Старый файл не нужно удалять до успешной проверки сайта:
новый код его игнорирует. Не используйте `sudo git pull`.

### Необязательный стенд Docker Compose

Для отдельно существующего стенда на базе `compose.yml` выходной PHP
создаётся в `config/dtpstat-config.php`, после чего монтируется
в контейнеры `wp` и `wpcli` как
`/var/www/html/wp-content/dtpstat-config.php` (read-only):

```bash
python3 bin/generate-config.py --output "$(pwd)/config/dtpstat-config.php"
```

До создания файла `docker compose up` не запускайте; Compose не
транслирует переменные `DTP_*` напрямую в PHP. Старый
`bin/frontend-url.sh` отключён, поскольку писал `.env` в docroot.

## 4. Проверить связку

```bash
WP_URL=https://example.org
FRONT_URL=https://frontend.example.org

# WordPress, страница и asset темы:
curl -I "$WP_URL/"
curl -I "$WP_URL/wp-content/themes/dtpstat-child/assets/fonts/Roboto-Regular.woff2"

# Фронтенд:
curl -I "$FRONT_URL/"
curl -I "$FRONT_URL/details/?id=1"

# Доступность API за выбранным прокси:
curl -s -o /dev/null -w '%{http_code}\n' "$FRONT_URL/api/dtp/filter_fields"
```

Также проверьте HTML главной страницы и `/dtp/1/` в браузере: правильный iframe, отсутствие блокировки mixed content, загрузку карты и запросы к API. Значение HTTP 200 само по себе не подтверждает корректную работу приложения.

## 5. Обновления и устаревшие инструменты

Для повторной выкладки **только темы** используйте [DEPLOY.md](DEPLOY.md). Экспорт из Docker-песочницы выполняется отдельно через `bin/export.sh`; он не является установщиком продакшена. `compose.yml`, `bin/wp.sh`, `bin/frontend-url.sh` и `bin/verify.sh` сохранились как инструменты прежнего стенда, но новый `bin/install.sh` их не вызывает.
