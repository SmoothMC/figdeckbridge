#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")"/.. && pwd)"

php -l "$ROOT_DIR/poll-comments.php"
php -l "$ROOT_DIR/deck-api.php"
