# Тема dtpstat-child на готовом WordPress + фронтенд карты

Предпосылка: WordPress уже установлен (ядро, `wp-config.php` с базой).
Пакет накатывает на него тему `dtpstat-child` с контентом. Сама карта
ДТП — отдельный статический фронтенд, который WP встраивает айфреймами:
он собирается отдельно и подключается адресом (шаг 4). Управление —
скрипты из `bin/` этого репозитория.

| Часть | Где | Что это |
|---|---|---|
| WP-сайт | этот репозиторий | тема `dtpstat-child` + контент, накатка на готовый WP |
| Фронтенд карты | [dtpstat-pages](https://github.com/dtpstat/website-frontend-mask) | статика (vanilla TS → `dist/`) + сервер разработки с прокси `/api` |
| Бэкенд API | [dtpstat-map](https://github.com/dtpstat/dtpstat-map) | здесь не поднимается: фронтенду нужен адрес работающего экземпляра (`API_ORIGIN`) |

Связка: карта на главной WP — айфрейм фронтенда (URL зашит в **контент
WP-страницы**, правится `wp search-replace`), карточки отдельных ДТП
`/dtp/<EM_NUMBER>/` — айфрейм `/details/?id=…` (URL тема берёт из
переменной `DTP_FRONTEND_URL`).

## Требования

- установленный WordPress; wp-cli на его хосте (или своя команда в `--wp`);
- rsync и python3 (нужны `bin/install.sh`);
- Node ≥ 20.6 для сборки фронтенда (сборка и serve используют `node --env-file`).

## 1. Забрать код

```bash
git clone --recurse-submodules git@github.com:dtpstat/dtpstat-wp-theme.git
git clone git@github.com:dtpstat/website-frontend-mask.git
```

Если WP на другой машине — доставьте пакет на её хост (скрипты запускаются
**на самом хосте**: wp-cli-вызовы с eval через ssh теряют кавычки):

```bash
rsync -a dtpstat-wp-theme/ host:~/dtpstat-wp-theme/
```

## 2. Накатить тему на WordPress

```bash
cd dtpstat-wp-theme
bin/install.sh --external --url https://example.org \
  --wp "wp --path=/path/to/site" \
  --admin-pass 'пароль'   # нужен, только если ядро ещё не установлено (install сам сделает core install)

bin/verify.sh https://example.org --external "wp --path=/path/to/site"
```

`install` накатывает всё остальное: тему (neve с пином версии из
`content/settings.json` + `dtpstat-child`), плагины, медиа (~89 МБ,
с ручными кропами), контент (страницы/посты/CPT), настройки и меню.
Сразу после этого WP показывает **задеплоенный** фронтенд
`https://dtpstat-pages.pages.dev` — система работает целиком, свой
фронтенд подключается шагом 4.

- непустая цель (повторная накатка) — см. «Грабли»;
- если PHP работает под другим пользователем, отдайте ему `wp-content`
  (группа + запись, setgid на каталогах), иначе медиа не зальётся.

Если готового WordPress нет, `bin/install.sh` без `--external` поднимет
собственный стенд на `:8086` (админ `ilya`/`wplocal`; сброс — `--reset`,
порт — `WP_PORT=…`; первый старт занимает несколько минут).

## 3. Фронтенд (`website-frontend-mask`)

```bash
cd website-frontend-mask
cp .env.example .env     # вписать YANDEX_MAPS_KEY, MAPBOX_ACCESS_TOKEN и API_ORIGIN
npm install
npm run build            # tsc + копирование статики → dist/; из .env генерится dist/maps-config.js
PORT=8180 npm run serve  # любой свободный порт (дефолт 8080); раздаёт dist/ и проксирует /api
```

Проверка: `curl http://localhost:8180/api/dtp/filter_fields` → 200 — прокси
до бэкенда жив.

- `API_ORIGIN` — адрес работающего экземпляра `dtpstat-map`; для прокси
  serve он **обязателен**: с пустым значением `/api` будет отвечать 502
  (same-origin `/api` — это вариант с nginx ниже).
- serve умеет https (`DTP_CERT`/`DTP_KEY`) — нужно, только если встраивать
  фронтенд айфреймом в https-сайт (см. «Грабли»).

### Вариант без serve: статика + nginx

`npm run serve` нужен только для разработки. Собранный `dist/` —
самодостаточная статика (серверного кода нет, клиентской маршрутизации
тоже — deep-links передаются query-параметрами, SPA-fallback не нужен),
её отдаёт любой статический сервер. Заодно nginx даёт same-origin `/api`:

```nginx
server {
    listen 80;
    server_name frontend.example.org;

    root /path/to/website-frontend-mask/dist;
    index index.html;

    # /api того же origin — вместо прокси serve.
    # Тогда при сборке API_ORIGIN в .env оставьте пустым: пустое
    # значение вшивается в статику, и страница ходит на свой /api
    location /api/ {
        proxy_pass https://api.example.org/api/;
        proxy_set_header Host api.example.org;
        proxy_ssl_server_name on;
    }

    location / { try_files $uri $uri/ =404; }
}
```

Кэш-правила для версионированных ассетов (`?v=`), свежего `maps-config.js`
и HTML — в README фронтенда, раздел «Самостоятельный хостинг». Проверка
всё та же: `curl http://frontend.example.org/api/dtp/filter_fields` → 200.

## 4. Подключить WP к своему фронтенду

Две настройки: айфреймы карточек `/dtp/<id>/` (берёт тема) и айфрейм
карты на главной (он в контенте WP-страницы):

```bash
# айфреймы темы: переменная DTP_FRONTEND_URL в .env в каталоге WP
# или уровнем выше — тема читает её на каждом запросе
printf 'DTP_FRONTEND_URL=%s\n' 'https://frontend.example.org' >> /path/to/.env
# айфрейм карты на главной — правится wp-cli по всем таблицам:
wp --path=/path/to/site search-replace 'https://dtpstat-pages.pages.dev' \
  'https://frontend.example.org' --all-tables
```

(этими же двумя настройками указывается любой другой адрес фронтенда;
для стенда пакета из шага 2 вместо этого есть скрипты:
`bin/frontend-url.sh <URL>` — вернуть дефолт `--default`, и
`bin/wp.sh search-replace …`)

## Проверка системы целиком

```bash
SITE=https://example.org; FRONT=https://frontend.example.org
# WP отдаёт главную с айфреймом фронтенда:
curl -s "$SITE/" | grep -cF "$FRONT"                                 # ≥ 1
# карточка ДТП строит айфрейм деталей:
curl -s "$SITE/dtp/1/" | grep -o 'details/?id=1'                     # → details/?id=1
# фронтенд жив и проксирует API:
curl -so /dev/null -w '%{http_code}\n' "$FRONT/"                     # 200
curl -so /dev/null -w '%{http_code}\n' "$FRONT/details/?id=1"        # 200
curl -so /dev/null -w '%{http_code}\n' "$FRONT/api/dtp/filter_fields" # 200
```

## Грабли

- **Повторная накатка** на непустую цель падает (защита от дублей
  контента): либо чистая цель (на сервере — дамп, дроп таблиц по префиксу
  и пустой каталог WP), либо осознанный `--force`. Для стенда пакета —
  `bin/install.sh --reset`.
- **Ключи карт обязательны**: сборка с пустым `.env` даст `dist/maps-config.js`
  без ключей (карта не откроется). Пересоздать только конфиг без пересборки —
  `npm run config`.
- **Mixed content**: если WP открыт по https, айфрейм на
  `http://localhost:…` браузер заблокирует. Тогда фронтенду нужен https
  (`DTP_CERT`/`DTP_KEY` в serve или nginx с сертификатом) и оба адреса
  из шага 4 — с его https-адресом.

## Что осознанно не поднимается

**Бэкенд** (`dtpstat-map`, Express + PostGIS): фронтенд работает с готовым
экземпляром через `API_ORIGIN`; своя инсталляция описана в его `DEPLOY.md`.
