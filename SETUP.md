# Подъём системы с нуля: WordPress + тема + фронтенд

Пакет ставит WP-сайт с темой `dtpstat-child`; сама карта ДТП — отдельный
статический фронтенд, который WP встраивает айфреймами. Этот документ —
как поднять всё вместе на чистой машине. Управление стендом — скрипты из
`bin/` этого репозитория.

| Часть | Где | Что это |
|---|---|---|
| WP-сайт | этот репозиторий | тема `dtpstat-child` + контент, накатка на чистый WP; стенд на `:8086` |
| Фронтенд карты | [dtpstat-pages](https://github.com/dtpstat/website-frontend-mask) | статика (vanilla TS → `dist/`) + сервер разработки с прокси `/api` |
| Бэкенд API | [dtpstat-map](https://github.com/dtpstat/dtpstat-map) | здесь не поднимается: фронтенду нужен адрес работающего экземпляра (`API_ORIGIN`) |

Связка: карта на главной WP — айфрейм фронтенда (URL зашит в **контент
WP-страницы**, правится `wp search-replace`), карточки отдельных ДТП
`/dtp/<EM_NUMBER>/` — айфрейм `/details/?id=…` (URL тема берёт из
переменной `DTP_FRONTEND_URL`).

## Требования

- Node ≥ 20.6 (сборка и сервер разработки используют `node --env-file`);
- python3 (`bin/install.sh` читает `content/settings.json`).

## 1. Забрать код

```bash
git clone --recurse-submodules git@github.com:dtpstat/dtpstat-wp-theme.git
git clone git@github.com:dtpstat/website-frontend-mask.git
```

## 2. Фронтенд (`website-frontend-mask`)

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

## 3. WordPress + тема (этот репозиторий)

```bash
cd dtpstat-wp-theme
bin/install.sh   # поднимет стенд (WP 6.9.4 + MariaDB 11.4) на :8086 и накатит тему и контент
bin/verify.sh    # сверка результата с эталоном content/settings.json
```

Сайт: http://localhost:8086, админ `ilya` / `wplocal` (или `$ADMIN_PASSWORD`).
Сразу после установки WP показывает **задеплоенный** фронтенд
`https://dtpstat-pages.pages.dev` — система уже работает целиком, локальный
фронтенд подключается шагом 4.

## 4. Переключить WP на локальный фронтенд

```bash
cd dtpstat-wp-theme
# айфреймы темы (карточки /dtp/<id>/):
bin/frontend-url.sh http://localhost:8180
# айфрейм карты на главной — он в контенте WP-страницы, правится через wp-cli:
bin/wp.sh search-replace 'https://dtpstat-pages.pages.dev' 'http://localhost:8180' --all-tables
```

(этими же двумя настройками указывается любой другой адрес фронтенда,
например https-адрес публичного стенда; вернуть дефолт —
`bin/frontend-url.sh --default`)

## Установка на существующий WordPress (`--external`)

Вместо стенда пакет накатывается на уже поднятый WP (свой хостинг, vhost,
база). Скрипты запускаются **на самом хосте**: wp-cli-вызовы с eval через
ssh теряют кавычки. Понадобятся wp-cli и rsync.

```bash
bin/install.sh --external --url https://example.org \
  --wp "wp --path=/path/to/site"
bin/verify.sh https://example.org --external "wp --path=/path/to/site"
```

- пустой WP (ядро не установлено) — не помеха, install сам сделает
  core install; непустая цель — см. «Грабли» про повторную накатку;
- если PHP работает под другим пользователем, отдайте ему `wp-content`
  (группа + запись, setgid на каталогах), иначе медиа не зальётся.

Адрес фронтенда (шаг 4) ставится теми же двумя настройками, но напрямую —
скрипты `bin/frontend-url.sh` и `bin/wp.sh` работают только со стендом:

```bash
# айфреймы темы (карточки /dtp/<id>/): переменная DTP_FRONTEND_URL
# в .env в каталоге WP или уровнем выше — тема читает её на каждом запросе
printf 'DTP_FRONTEND_URL=%s\n' 'https://frontend.example.org' >> /path/to/.env
# айфрейм карты на главной — он в контенте WP-страницы:
wp --path=/path/to/site search-replace 'https://dtpstat-pages.pages.dev' \
  'https://frontend.example.org' --all-tables
```

## Проверка системы целиком

```bash
# WP отдаёт главную с айфреймом фронтенда:
curl -s http://localhost:8086/ | grep -c 'localhost:8180'          # ≥ 1
# карточка ДТП строит айфрейм деталей:
curl -s http://localhost:8086/dtp/1/ | grep -o 'details/?id=1'     # → details/?id=1
# фронтенд жив и проксирует API:
curl -so /dev/null -w '%{http_code}\n' http://localhost:8180/                      # 200
curl -so /dev/null -w '%{http_code}\n' 'http://localhost:8180/details/?id=1'       # 200
curl -so /dev/null -w '%{http_code}\n' http://localhost:8180/api/dtp/filter_fields # 200
```

## Грабли

- **Повторный `bin/install.sh`** на непустую цель падает (защита от дублей
  контента): сначала `bin/install.sh --reset` — снесёт данные стенда и
  накатит всё заново.
- **Первый старт** занимает несколько минут: скачиваются компоненты стенда
  и копируется ~89 МБ медиа.
- **Ключи карт обязательны**: сборка с пустым `.env` даст `dist/maps-config.js`
  без ключей (карта не откроется). Пересоздать только конфиг без пересборки —
  `npm run config`.
- **Mixed content**: если WP открыт по https (туннель), айфрейм на
  `http://localhost:…` браузер заблокирует. Тогда фронтенду нужен https
  (`DTP_CERT`/`DTP_KEY` в serve) и оба адреса из шага 4 — с его https-адресом.
- **Порт стенда WP** меняется так: `WP_PORT=9090 bin/install.sh` — URL стенда при
  этом переписывается в контенте сам (install делает search-replace на свой `$URL`).

## Что осознанно не поднимается

**Бэкенд** (`dtpstat-map`, Express + PostGIS): фронтенд работает с готовым
экземпляром через `API_ORIGIN`; своя инсталляция описана в его `DEPLOY.md`.
