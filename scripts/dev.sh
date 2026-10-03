#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

cmd="${1:-help}"
ZIP=$(ls ../xenforo_*.zip 2>/dev/null | head -n 1 || true)

case "$cmd" in
  up)
    cp -n .env.example .env || true
    docker compose up -d --build
    docker compose ps
    ;;
  down)
    docker compose down
    ;;
  unpack)
    if [ -z "$ZIP" ]; then echo "No xenforo_*.zip found in parent dir"; exit 1; fi
    mkdir -p src
    echo "Unpacking $ZIP -> src/"
    rm -rf tmp_unpack && mkdir -p tmp_unpack
    unzip -o "$ZIP" -d tmp_unpack >/dev/null
    cp -r tmp_unpack/upload/. src/
    rm -rf tmp_unpack
    chmod -R 0777 src/data src/internal_data || true
    ls src/ | head
    ;;
  enable-debug)
    CFG="src/src/config.php"
    if [ ! -f "$CFG" ]; then echo "Install XF first (src/src/config.php missing)"; exit 1; fi
    grep -q "debug.*true" "$CFG" || cat >> "$CFG" <<'PHP'

$config['debug'] = true;
$config['development']['enabled'] = true;
PHP
    echo "debug enabled in $CFG"
    ;;
  link-addon)
    # addon-src is bind-mounted ro into the web container (see docker-compose.yml).
    docker compose exec web ls -la /var/www/html/src/addons/OpenCredits/Credits/
    docker compose exec web php /var/www/html/cmd.php xf-addon:rebuild OpenCredits/Credits || true
    ;;
  logs)
    docker compose logs -f "${2:-web}"
    ;;
  *)
    echo "usage: dev.sh {up|down|unpack|enable-debug|link-addon|logs}"
    ;;
esac
