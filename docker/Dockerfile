# syntax=docker/dockerfile:1.7

# Golden Hive WordPress image.
#
# Two build targets:
#   * `dev`  — extends wordpress:php8.2-apache with debug-friendly PHP config,
#              WP-CLI and Composer. The plugin is mounted as a bind volume by
#              docker-compose.dev.yml so edits are live.
#   * `prod` — same base, hardened php.ini, plugin sources COPYed in. Built and
#              pushed (or built directly on the VPS) for docker-compose.prod.yml.
#
# Build:
#   docker build --target dev  -t golden-hive:dev  -f docker/Dockerfile .
#   docker build --target prod -t golden-hive:prod -f docker/Dockerfile .

ARG WORDPRESS_TAG=6.5-php8.2-apache

# ---------- shared base ----------------------------------------------------
FROM wordpress:${WORDPRESS_TAG} AS base

# OS deps used by WP-CLI, Composer and a few WP image-handling extensions.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        less \
        git \
        unzip \
        default-mysql-client \
        libzip-dev \
    && docker-php-ext-install zip opcache \
    && rm -rf /var/lib/apt/lists/*

# Composer (pinned via official image).
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# WP-CLI.
RUN curl -fsSL -o /usr/local/bin/wp \
        https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp

# Apache: enable rewrite (WordPress permalinks).
RUN a2enmod rewrite

# ---------- dev target -----------------------------------------------------
FROM base AS dev

# Install Xdebug for step-debugging from the host IDE.
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

COPY docker/config/php-dev.ini /usr/local/etc/php/conf.d/zz-golden-hive.ini

# The plugin is mounted as a volume in dev — no COPY here.

# ---------- prod target ----------------------------------------------------
FROM base AS prod

COPY docker/config/php-prod.ini /usr/local/etc/php/conf.d/zz-golden-hive.ini

# Copy the plugin into the image so the container is self-contained.
# vendor/ is committed (no-dev) per the project gitignore, so no composer
# install is required at build time.
COPY golden-hive/ /usr/src/golden-hive/

# Drop into the WordPress plugins dir on first run via an entrypoint shim.
COPY docker/scripts/prod-entrypoint.sh /usr/local/bin/golden-hive-entrypoint
RUN chmod +x /usr/local/bin/golden-hive-entrypoint

ENTRYPOINT ["golden-hive-entrypoint"]
CMD ["apache2-foreground"]
