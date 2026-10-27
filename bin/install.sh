#!/usr/bin/env bash
# Установка пакета в локальный, уже установленный WordPress.
set -Eeuo pipefail
command -v python3 >/dev/null 2>&1 || {
  printf '%s\n' 'ОШИБКА: требуется python3 (>= 3.8).' >&2
  exit 127
}
exec python3 "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/install.py" "$@"
