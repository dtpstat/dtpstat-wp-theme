#!/usr/bin/env bash
# Локальное обновление WordPress на его сервере.
# Не делает git pull, не импортирует контент и не обновляет сервисы.
# Обе операции — генерация PHP-конфига и переключение темы — внутри deploy.py.
set -Eeuo pipefail
command -v python3 >/dev/null 2>&1 || {
    printf '%s\n' 'ОШИБКА: требуется python3 (>= 3.8).' >&2
    exit 127
}
exec python3 "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/deploy.py" "$@"
