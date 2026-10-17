#!/usr/bin/env bash
# Разовая wp-cli команда на стенде пакета: bin/wp.sh <команда> [аргументы…]
# (на стенд вне пакета — bin/install.sh --external --url …)
set -euo pipefail
cd "$(dirname "$0")/.."
exec docker compose run --rm wpcli wp "$@" --allow-root
