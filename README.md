# dtpstat-wp-theme

Самодостаточный пакет сайта [dtp-stat.ru](https://dtp-stat.ru) («Карта ДТП»):
тема `dtpstat-child` + контент + медиа, который накатывается на чистый WordPress
и даёт то же состояние, что рабочая песочница.

```bash
bin/install.sh                    # поднимет стенд compose на :8086 и накатит всё
bin/verify.sh                     # проверит результат против content/settings.json
# → http://localhost:8086 (админ ilya, пароль wplocal или $ADMIN_PASSWORD)
```

Порт стенда меняется через `WP_PORT=9090 bin/install.sh` (URL подставится сам).
На существующий WP с доступным wp-cli: `bin/install.sh --external --url https://site`
(цель должна быть **пустой** — иначе внутренние id медиа не сохранить).

## Что внутри

```
compose.yml            стенд: WP 6.9.4 + MariaDB 11.4 + сервис wpcli, порт 8086
php-uploads.ini        лимиты загрузки PHP для стенда
theme/dtpstat-child/   тема (примаунчена в стенд: правки видны сразу)
content/
  export.xml           WXR: страницы, посты, CPT (команда/спонсоры), wp_block
  attachments.json     записи медиа со всем meta (см. «Почему медиа не в WXR»)
  uploads/             wp-content/uploads целиком, все сгенерированные размеры
  settings.json        настройки, категории, меню, версии плагин/тем, эталонные счётчики
bin/
  export.sh            обновить пакет из живой песочницы (контейнер local-wp-1, :8080)
  install.sh           накатка на чистый WP
  verify.sh            проверка накатки (счётчики, настройки, HTTP, маркеры вёрстки, кропы)
  attachments.php      дамп/восстановление записей медиа (wp eval-file, оба скрипта его зовут)
```

Порядок накатки (`bin/install.sh`): ядро (ru_RU) → neve (пин версии) + dtpstat-child →
плагины (пины из settings.json) → uploads целиком → медиа-записи с исходными id →
WXR-импорт контента → search-replace URL → настройки/категории/меню (WXR их не
переносит) → реврайты.

## Обновление пакета

После правок в песочнице (:8080):

```bash
bin/export.sh        # перезапишет content/ из контейнера local-wp-1
# закоммитить: export.xml, attachments.json, settings.json, uploads/ (и theme/ при правках)
```

## Тема dtpstat-child

Дочерняя тема [Neve](https://themeisle.com/themes/neve/), реализует вёрстку по
макету `dtpstat-design.fig`:

- Типографика и палитра по макету (шрифты Roboto подключены локально)
- Донат-блоки: шорткоды `dtp_fund` / `dtp_fund_bar` (сборы через Boosty / Patreon / Tribute)
- Фильтры и поиск в блоге (раскрываются на месте списка)
- Страницы «Команда», «Спонсоры», «Вакансии» — через CPT `dtp_person` / `dtp_sponsor` и шорткоды
- Кроп-размеры изображений `dtp-*` (карточки команды, вакансий, обложки)
- Хедер/меню/футер по макету, промо-карты, SVG-иконки

Тема не требует сборки: PHP/JS/CSS лежат как есть, кэш-версия ассетов задаётся в `functions.php`.

## Ключевые решения и грабли

**Почему медиа не через WXR.** Импортёр wordpress-importer для аттачментов всегда
скачивает файл по URL из WXR; при уже разложенных файлах он плодит копии с суффиксом
`-1` и затирает `_wp_attachment_metadata` сгенерированным — ручные кропы
crop-thumbnails (`cpt_last_cropping_data` в sizes) теряются. Поэтому файлы копируются
uploads'ом целиком, а записи создаёт `bin/attachments.php restore`:
`wp_insert_attachment` с `import_id` (исходные id → валидны ссылки `wp-image-N`,
`{"id":N}` в блоках и `_thumbnail_id`) + meta как есть. Из WXR аттачменты перед
импортом вырезаются построчно.

**wp-cli — отдельным контейнером.** Внутри `wordpress:*-apache` wp-cli нет; в compose
есть сервис `wpcli` (`wordpress:cli-php8.3`, user 33:33) с общим сетевым стеком с `wp`
(`network_mode: "service:wp"`) — WP валидирует URL через `wp_http_validate_url()`,
который пускает только localhost и публичные хосты, так что «сам сайт» должен быть
виден именно на `localhost`.

**Кропы живут только при цельном uploads.** Никакой регенерации миниатюр — только
копирование `wp-content/uploads` как есть (89 МБ со всеми размерами, коммитятся в git).

**Порядок важен.** Медиа-записи — ДО WXR-импорта: в песочнице id медиа 111–404,
на чистой базе импортёр начинает с id 2, коллизий нет; наоборот — получил бы пересечения.

**Мелочи.** Пустые категории (Краудфандинг, «Обзорные статьи») и меню WXR не
перевозит — их восстанавливает install.sh по settings.json; стоковое «Hello World» /
«Sample Page» / черновик Privacy Policy от `wp core install` удаляются; версия темы в
`functions.php`, плагинов — пинами из settings.json. Висячие ссылки `wp-image-N` и
`{"id":N}` на удалённые в песочнице медиа воспроизводятся как есть (они там уже битые).

## Товарные знаки

Логотипы Boosty, Patreon, Tribute, Telegram, Instagram и Twitter в
`theme/dtpstat-child/assets/img/` используются функционально (кнопки-ссылки на
профильные сервисы) и принадлежат их владельцам.

## Лицензия

Код — [GPL-2.0-or-later](LICENSE), как и родительская тема Neve.
Шрифты Roboto — [SIL OFL](https://fonts.google.com/specimen/Roboto/about).
Контент сайта (тексты и медиа) принадлежит проекту «Карта ДТП».
