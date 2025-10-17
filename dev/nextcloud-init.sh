#!/usr/bin/env bash
set -euo pipefail

COMPOSE_FILE="${COMPOSE_FILE:-dev/docker-compose.nextcloud.yml}"
COMPOSE="${COMPOSE_COMMAND:-docker compose} -f ${COMPOSE_FILE}"

info() { printf '\033[1;34m[figdeck]\033[0m %s\n' "$*"; }

ensure_up() {
  if ! ${COMPOSE} ps --status running nextcloud >/dev/null 2>&1; then
    info "Starting Nextcloud stack"
    ${COMPOSE} up -d
  fi
}

wait_for_nextcloud() {
  info "Warte darauf, dass Nextcloud die initiale Installation abschließt (dies kann bis zu fünf Minuten dauern)"
  local retries=60
  until ${COMPOSE} exec -T -u www-data nextcloud php occ status >/dev/null 2>&1; do
    ((retries--)) || {
      echo "Nextcloud hat die Installation nicht rechtzeitig abgeschlossen" >&2
      exit 1
    }
    sleep 5
  done
}

run_occ() {
  ${COMPOSE} exec -T -u www-data nextcloud php occ "$@"
}

ensure_up
wait_for_nextcloud

info "Installing Deck app"
run_occ app:install deck 2>/dev/null || info "Deck already installed"
run_occ app:enable deck

if ! run_occ deck:board:list | grep -q "FigDeck Demo"; then
  info "Creating demo board"
  run_occ deck:board:create "FigDeck Demo" "Feedback board for local testing"
  run_occ deck:stack:add "FigDeck Demo" "Inbox"
  run_occ deck:stack:add "FigDeck Demo" "Doing"
  run_occ deck:stack:add "FigDeck Demo" "Done"
fi

info "Ensuring admin user has Deck access"
run_occ deck:user:add admin "FigDeck Demo" --role editor >/dev/null 2>&1 || true

info "All set. Access Nextcloud via http://localhost:8080 with admin/admin"
info "Create an app password in the personal settings for use with FigDeck Bridge."
