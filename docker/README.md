# Docker setup

Two stacks share a single `docker/Dockerfile` (multi-stage build):

| Stack | File | Use case |
|---|---|---|
| Dev   | `docker/docker-compose.dev.yml`  | Docker Desktop on your laptop. Plugin source bind-mounted live. |
| Prod  | `docker/docker-compose.prod.yml` | Single-host VPS (Contabo). Caddy + auto-HTTPS, daily DB backups. |

Both publish a working WordPress + WooCommerce site with Golden Hive activated, so you can exercise the plugin end-to-end.

---

## Local development (Docker Desktop)

```bash
# 1. Bootstrap env file (defaults are fine)
cp docker/.env.dev.example docker/.env.dev

# 2. Build + start
docker compose -f docker/docker-compose.dev.yml --env-file docker/.env.dev up -d --build
#   …or with the Makefile:  make dev-up

# 3. Install WP core, WooCommerce, activate Golden Hive
make dev-init
#   equivalent: docker compose -f docker/docker-compose.dev.yml exec wordpress bash docker/scripts/wp-init.sh
```

Open:

| URL | What |
|---|---|
| <http://localhost:8080>           | The WordPress site |
| <http://localhost:8080/wp-admin>  | Admin (default user `admin` / `admin`) |
| <http://localhost:8081>           | Adminer (server `db`, user `wordpress`, pwd `wordpress`) |
| <http://localhost:8025>           | MailHog — every `wp_mail()` lands here |

The plugin directory `golden-hive/` is bind-mounted into `wp-content/plugins/golden-hive`, so saving a file in your editor is immediately visible in the browser. WordPress core + uploads + other plugins live in the named `wp_core` volume.

### Common dev tasks

```bash
make dev-shell            # bash in the wordpress container
make dev-logs             # tail container logs
make dev-test             # run the plugin's PHPUnit suite
make dev-wp ARGS="plugin list"   # one-off wp-cli
make dev-reset            # nuke WP install + DB volume and start fresh
```

### Xdebug

`docker/config/php-dev.ini` enables Xdebug 3 in `develop,debug` mode with `start_with_request = trigger`. Configure your IDE to listen on port `9003` and set the IDE key to `GOLDEN_HIVE`. The container resolves `host.docker.internal` to the host on Linux via the `extra_hosts` entry in the dev compose.

Trigger debugging by appending `?XDEBUG_TRIGGER=1` to a URL or installing a browser helper (xdebug-helper).

---

## Production (Contabo VPS)

### One-time host setup

```bash
# Engine + compose plugin
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER && newgrp docker

# Clone the repo where the stack will live
sudo mkdir -p /opt/golden-hive && sudo chown $USER /opt/golden-hive
git clone https://github.com/FrancescoCorbosiero/golden-hive-plugin.git /opt/golden-hive
cd /opt/golden-hive

# DNS: point an A record for $SITE_DOMAIN to the VPS IP.
# Contabo firewall: open TCP 80 + 443 (Caddy needs both for ACME).
```

### First deploy

```bash
cp docker/.env.prod.example docker/.env.prod
$EDITOR docker/.env.prod          # set SITE_DOMAIN, ACME_EMAIL, DB_*

docker compose -f docker/docker-compose.prod.yml --env-file docker/.env.prod up -d --build
#   …or with the Makefile:  make prod-up

# Bootstrap WordPress (run once)
docker compose -f docker/docker-compose.prod.yml exec wordpress \
    wp --allow-root core install \
        --url="https://$SITE_DOMAIN" \
        --title="Golden Hive" \
        --admin_user=<your-user> --admin_password=<strong-pw> --admin_email=<you@example.com>

docker compose -f docker/docker-compose.prod.yml exec wordpress \
    wp --allow-root plugin install woocommerce --activate

docker compose -f docker/docker-compose.prod.yml exec wordpress \
    wp --allow-root plugin activate golden-hive
```

Caddy will obtain a Let's Encrypt cert for `$SITE_DOMAIN` automatically on first request — first hit may take 10–30 s.

### Subsequent deploys

```bash
# On the VPS:
make prod-deploy          # git pull + rebuild + rolling restart
```

The plugin source is **baked into the production image** (`COPY golden-hive/ /usr/src/golden-hive/`) and synced into `wp-content/plugins/golden-hive` on every container start by `docker/scripts/prod-entrypoint.sh`. This means:

* Persistent volume holds uploads, themes, third-party plugins, `.htaccess`, etc.
* Each new image revision deterministically replaces the Golden Hive plugin directory — no drift, no manual `wp plugin update`.

### Backups

`db-backup` service writes a gzipped `mariadb-dump` to `${BACKUP_DIR:-./backups}` every 24 h, keeping `${BACKUP_KEEP_DAYS:-14}` files. Restore:

```bash
gunzip -c /var/backups/golden-hive/golden_hive-YYYYMMDD-HHMMSS.sql.gz | \
    docker compose -f docker/docker-compose.prod.yml exec -T db \
        mariadb -u root -p"$DB_ROOT_PASSWORD" "$DB_NAME"
```

Sync the backup directory to off-host storage (S3, B2, rsync.net) via cron — the docker stack does NOT do that for you.

### Operational checks

```bash
make prod-logs                            # tail everything
docker compose -f docker/docker-compose.prod.yml ps
docker compose -f docker/docker-compose.prod.yml exec wordpress wp --allow-root plugin list
docker compose -f docker/docker-compose.prod.yml exec caddy caddy validate --config /etc/caddy/Caddyfile
```

---

## File layout

```
docker/
├── Dockerfile                  multi-stage: base → dev / prod
├── docker-compose.dev.yml      dev stack
├── docker-compose.prod.yml     prod stack
├── .env.dev.example
├── .env.prod.example
├── config/
│   ├── php-dev.ini             debug + Xdebug
│   ├── php-prod.ini            opcache + realpath cache
│   └── Caddyfile               TLS, security headers, edge cache
└── scripts/
    ├── wp-init.sh              one-shot WP/WC bootstrap (dev)
    ├── prod-entrypoint.sh      syncs baked-in plugin on container start
    ├── test.sh                 phpunit inside the container
    └── deploy.sh               git pull + rebuild + up (prod)
.dockerignore                   build-context excludes (sibling plugins, .git, …)
Makefile                        thin wrappers around docker compose
```
