#!/usr/bin/env bash
# Bootstrap a fresh dev WordPress: install core, activate WooCommerce + Hive Commerce,
# set permalinks, point wp_mail at MailHog. Idempotent — safe to re-run.
#
# Run inside the dev container:
#   docker compose -f docker/docker-compose.dev.yml exec wordpress bash docker/scripts/wp-init.sh
set -euo pipefail

cd /var/www/html

WP="wp --allow-root"

site_url="${WP_SITEURL:-http://localhost:8080}"
title="${WP_TITLE:-Hive Commerce Dev}"
admin_user="${WP_ADMIN_USER:-admin}"
admin_pass="${WP_ADMIN_PASSWORD:-admin}"
admin_email="${WP_ADMIN_EMAIL:-admin@example.test}"

if ! $WP core is-installed 2>/dev/null; then
    echo "[wp-init] installing WordPress core"
    $WP core install \
        --url="$site_url" \
        --title="$title" \
        --admin_user="$admin_user" \
        --admin_password="$admin_pass" \
        --admin_email="$admin_email" \
        --skip-email
fi

echo "[wp-init] ensuring WooCommerce is installed"
if ! $WP plugin is-installed woocommerce; then
    $WP plugin install woocommerce --activate
else
    $WP plugin activate woocommerce || true
fi

echo "[wp-init] activating golden-hive"
$WP plugin activate golden-hive

if [ -d /var/www/html/wp-content/plugins/hive-sync ]; then
    echo "[wp-init] activating hive-sync"
    $WP plugin activate hive-sync
fi

echo "[wp-init] setting pretty permalinks"
$WP rewrite structure '/%postname%/' --hard

echo "[wp-init] done — log in at $site_url/wp-admin (user: $admin_user)"
