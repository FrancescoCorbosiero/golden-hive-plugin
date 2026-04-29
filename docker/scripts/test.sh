#!/usr/bin/env bash
# Run the plugin's PHPUnit suite inside the dev WordPress container.
#
#   docker compose -f docker/docker-compose.dev.yml exec wordpress bash docker/scripts/test.sh
#
# Composer dev deps are installed on first run into the host-mounted
# vendor/ directory (the plugin's .gitignore deliberately excludes the
# dev-only paths).
set -euo pipefail

cd /var/www/html/wp-content/plugins/golden-hive

if [[ ! -f vendor/bin/phpunit ]]; then
    echo "[test] installing composer dev dependencies"
    composer install --prefer-dist --no-interaction --no-progress
fi

exec vendor/bin/phpunit "$@"
