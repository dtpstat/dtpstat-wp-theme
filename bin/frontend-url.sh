#!/usr/bin/env bash
# Устаревший инструмент: больше не пишем .env в корень контейнера WordPress.
# Конфигурация компилируется в PHP до запуска/обновления контейнера.
set -euo pipefail
cat >&2 <<'EOF'
bin/frontend-url.sh удалён из рабочего процесса: WordPress больше не читает .env.
Измените DTP_FRONTEND_URL и DTP_MAP_URL в проектном .env, затем выполните:

  python3 bin/generate-config.py --output "$(pwd)/config/dtpstat-config.php"

Для обычного WordPress укажите абсолютный WP_CONTENT_DIR/dtpstat-config.php.
В Compose этот файл монтируется внутрь wp и wpcli (см. SETUP.md).
EOF
exit 2
