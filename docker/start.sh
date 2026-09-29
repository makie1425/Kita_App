#!/bin/bash
set -Eeuo pipefail
cd /var/www/html

: "${APP_KEY:?Set a stable APP_KEY in the service environment before starting KITA.}"
export PORT="${PORT:-10000}"
if [[ ! "$PORT" =~ ^[0-9]+$ ]] || (( PORT < 1 || PORT > 65535 )); then
    echo 'PORT must be an integer between 1 and 65535.' >&2
    exit 1
fi

mkdir -p storage/app/public storage/app/private storage/logs \
    storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R u+rwX,g+rX,o-rwx storage bootstrap/cache
# Copy the runtime mount before dropping privileges; keep the CA outside public storage.
if [[ -n "${MYSQL_ATTR_SSL_CA:-}" ]]; then
    php docker/prepare-mysql-ca.php
    chown www-data:www-data storage/app/private/mysql-ca.pem
    chmod 600 storage/app/private/mysql-ca.pem
    export MYSQL_ATTR_SSL_CA=/var/www/html/storage/app/private/mysql-ca.pem
    gosu www-data test -r "$MYSQL_ATTR_SSL_CA"
fi
# Nginx runs as www-data; the source code remains owned by root.
artisan() { gosu www-data php artisan "$@"; }
artisan config:clear
artisan route:clear
artisan view:clear

if [[ ! -L public/storage ]]; then
    if [[ -e public/storage ]]; then
        echo 'public/storage must be a symlink, not an existing file/directory.' >&2
        exit 1
    fi
    php artisan storage:link
fi

# Explicit opt-in only. Never run migrations concurrently across replicas.
if [[ "${RUN_MIGRATIONS:-false}" == 'true' ]]; then
    artisan migrate --force
fi
if [[ "${SEED_DEMO_USERS:-false}" == 'true' ]]; then
    artisan accounts:seed-demo
fi
artisan config:cache
if ! artisan route:cache; then
    echo 'Route cache unavailable; continuing with uncached routes.' >&2
    artisan route:clear
fi
artisan view:cache

# Replace only PORT, preserving Nginx's own $uri/$query_string variables.
envsubst '${PORT}' < /etc/nginx/templates/kita.conf.template > /etc/nginx/conf.d/kita.conf
nginx -t
php-fpm -t

php_pid=''
nginx_pid=''
stop_services() {
    trap - TERM INT
    [[ -z "$nginx_pid" ]] || kill -QUIT "$nginx_pid" 2>/dev/null || true
    [[ -z "$php_pid" ]] || kill -QUIT "$php_pid" 2>/dev/null || true
    wait || true
}
trap 'stop_services; exit 0' TERM INT
php-fpm -F &
php_pid=$!
# FPM starts asynchronously. Do not expose HTTP to Render's startup probes
# until the FastCGI listener exists; fail clearly if FPM exits or never binds.
fpm_ready=false
for ((attempt=1; attempt<=30; attempt++)); do
    if ! kill -0 "$php_pid" 2>/dev/null; then
        echo 'PHP-FPM exited before opening port 9000. Check the FPM errors above.' >&2
        stop_services
        exit 1
    fi
    if php -r '$socket = @fsockopen("127.0.0.1", 9000, $errno, $error, 0.5); if ($socket === false) { exit(1); } fclose($socket);'; then
        fpm_ready=true
        break
    fi
    sleep 1
done
if [[ "$fpm_ready" != true ]]; then
    echo 'PHP-FPM did not open port 9000 within the startup deadline.' >&2
    stop_services
    exit 1
fi
echo 'PHP-FPM listener ready; starting Nginx.'
nginx -g 'daemon off;' &
nginx_pid=$!
# Either server exiting ends the container, allowing Render to restart it.
set +e
wait -n "$php_pid" "$nginx_pid"
status=$?
set -e
stop_services
if [[ "$status" == 0 ]]; then status=1; fi
exit "$status"
