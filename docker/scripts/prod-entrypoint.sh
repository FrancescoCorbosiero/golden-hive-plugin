#!/usr/bin/env bash
# Production entrypoint for the golden-hive WordPress image.
#
# Strategy: delegate to the upstream WordPress docker-entrypoint.sh (which
# materialises WP core into /var/www/html and renders wp-config.php from
# env vars), then have it exec our post-init hook which syncs the
# baked-in plugin into wp-content/plugins before handing off to the CMD
# (typically apache2-foreground).
set -euo pipefail

POSTINIT="/usr/local/bin/golden-hive-postinit"

cat > "$POSTINIT" <<'POST'
#!/usr/bin/env bash
set -euo pipefail

PLUGIN_SRC="/usr/src/golden-hive"
PLUGIN_DST="/var/www/html/wp-content/plugins/golden-hive"

if [[ -d "$PLUGIN_SRC" ]]; then
    mkdir -p "$(dirname "$PLUGIN_DST")"
    rm -rf "$PLUGIN_DST"
    cp -a "$PLUGIN_SRC" "$PLUGIN_DST"
    chown -R www-data:www-data "$PLUGIN_DST"
fi

exec "$@"
POST
chmod +x "$POSTINIT"

# Upstream entrypoint will exec "$@" once setup is done — our $@ is
# `golden-hive-postinit apache2-foreground`, so the plugin sync runs
# right before Apache starts.
exec /usr/local/bin/docker-entrypoint.sh "$POSTINIT" "$@"
