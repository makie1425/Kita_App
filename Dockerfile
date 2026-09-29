# syntax=docker/dockerfile:1
FROM php:8.3-fpm-bookworm AS php-base

# Composer's locked requirements: mbstring, DOM/libxml and the built-in PHP
# extensions. PDO MySQL is required by KITA; OPcache is for production.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libonig-dev libxml2-dev libonig5 libxml2 \
    && docker-php-ext-install -j"$(nproc)" mbstring dom pdo_mysql opcache \
    && apt-get purge -y --auto-remove libonig-dev libxml2-dev \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html

FROM php-base AS dependencies
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-scripts --no-autoloader --no-interaction --no-progress
COPY . .
RUN mkdir -p bootstrap/cache storage/framework/views storage/framework/cache/data storage/framework/sessions \
    && composer dump-autoload --no-dev --optimize --no-interaction \
    && composer check-platform-reqs --no-dev

FROM node:22-bookworm-slim AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY resources ./resources
COPY vite.config.js ./
# Tailwind scans Laravel's pagination views as configured in app.css.
COPY --from=dependencies /var/www/html/vendor/laravel/framework/src/Illuminate/Pagination/resources/views ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
RUN npm run build

FROM php-base AS production
# Render mounts runtime secret files with group 1000. PHP-FPM and artisan run
# as www-data and need this group to read the MySQL CA certificate.
RUN groupadd --gid 1000 render-secrets \
    && usermod --append --groups render-secrets www-data
RUN apt-get update && apt-get install -y --no-install-recommends nginx gettext-base gosu curl \
    && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/default \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY --from=dependencies /var/www/html /var/www/html
COPY --from=frontend /app/public/build ./public/build
COPY docker/nginx.conf /etc/nginx/templates/kita.conf.template
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-kita.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-kita.conf
COPY docker/start.sh /usr/local/bin/kita-start
RUN chmod +x /usr/local/bin/kita-start \
    && mkdir -p storage/app/public storage/app/private storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr LOG_LEVEL=error PORT=10000
EXPOSE 10000
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl --fail --silent "http://127.0.0.1:${PORT}/up" > /dev/null || exit 1
ENTRYPOINT ["/usr/local/bin/kita-start"]
