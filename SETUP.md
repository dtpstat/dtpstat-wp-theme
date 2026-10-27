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

## 3. Связать WordPress с фронтендом через .env

**Адресов два:** `DTP_MAP_URL` задаёт iframe карты на главной, а
`DTP_FRONTEND_URL` — iframe карточек ДТП `/dtp/<id>/` с `/details/?id=…`.
Если `DTP_MAP_URL` отсутствует, главная использует `DTP_FRONTEND_URL`.
Жёстко заданных доменов по умолчанию в PHP-теме больше нет.

Скопируйте значения из [wordpress.env.example](wordpress.env.example) в
`.env` **рядом с каталогом WordPress (вне web-root)**. Если по условиям
хостинга доступен лишь `.env` внутри корня WordPress, настройте запрет
HTTP-доступа к нему. Не храните рабочие секреты в Git.

```dotenv
DTP_MAP_URL=https://map.example.org
DTP_FRONTEND_URL=https://frontend.example.org
DTP_CONTACT_EMAIL=contact@example.org
```

Тема читает переменные окружения PHP-процесса, затем `.env` из каталога
**над** WordPress и, для ещё не заданных ключей, `.env` из самого WP.
Переменные PHP-процесса имеют наивысший приоритет. Значения считываются
при каждом новом запросе; повторный импорт и `wp search-replace` не нужны.
Для кэшированных страниц потребуется обновить кэш страницы/CDN.

В импортируемом `content/export.xml` у iframe главной выставлен
`src="about:blank"` вместо фиксированного домена. При выводе главной
`front-page.php` берёт **первый iframe** из содержимого страницы и заменяет
его `src` на `DTP_MAP_URL`. Это также работает со старой базой, в которой
остался прежний адрес: править содержимое вручную не требуется. Если на
странице появятся другие iframe до карты, пересмотрите шаблон.

Социальные ссылки футера и контактные адреса также задаются в
`wordpress.env.example` через `DTP_SOCIAL_*_URL`,
`DTP_CONTACT_TELEGRAM_URL`, `DTP_CONTACT_TELEGRAM_LABEL` и
`DTP_CONTACT_EMAIL`. Пустые необязательные ссылки не выводятся.

**Важно при обновлении рабочей темы:** до деплоя заполните `.env`, иначе
iframe и контактные ссылки не будут выведены. Файл конфигурации не входит в
rsync-деплой темы. Установочный `--url` по-прежнему обозначает URL **самого
WordPress**, а не карты.

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
