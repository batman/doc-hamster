#!/bin/bash
# Self-provisioning entrypoint for frictionless try-outs.
# First boot creates .env, installs deps, migrates, and seeds a demo owner;
# later boots skip everything that's already done. Ends by starting Apache.
set -e
cd /var/www/html

TRY_ORG="${TRY_ORG:-Demo}"
TRY_USER="${TRY_USER:-owner}"
TRY_PASSWORD="${TRY_PASSWORD:-hamster-demo-01}"

if [ ! -f .env ]; then
    cp .env.example .env
    sed -i 's/^APP_ENV=.*/APP_ENV=local/; s/^APP_DEBUG=.*/APP_DEBUG=true/; s|^APP_URL=.*|APP_URL=http://localhost:8080|; s/^DB_HOST=.*/DB_HOST=db/; s/^DB_PASSWORD=.*/DB_PASSWORD=hamster-db-01/; s/^SESSION_SECURE_COOKIE=.*/SESSION_SECURE_COOKIE=false/' .env
    echo "[try] created .env with local defaults"
fi

chown -R www-data:www-data storage bootstrap/cache

if [ ! -f vendor/autoload.php ]; then
    echo "[try] installing dependencies…"
    composer install --no-interaction --no-progress --prefer-dist
fi

if ! grep -q '^APP_KEY=.\+' .env; then
    php artisan key:generate --force
fi

echo "[try] waiting for database…"
MIGRATED=0
for _ in $(seq 1 30); do
    if php artisan migrate --force >/tmp/migrate.log 2>&1; then
        MIGRATED=1
        break
    fi
    sleep 2
done
if [ "$MIGRATED" != "1" ]; then
    echo "[try] migrations failed:"
    tail -20 /tmp/migrate.log
    exit 1
fi

[ -L public/storage ] || php artisan storage:link

php artisan dochamster:owner --org="$TRY_ORG" --username="$TRY_USER" --password="$TRY_PASSWORD" \
    || echo "[try] owner account already exists, skipping"

sed -i 's/^DOCHAMSTER_INSTALLED=.*/DOCHAMSTER_INSTALLED=true/' .env

echo "[try] ready → http://localhost:8080 (login: $TRY_USER / $TRY_PASSWORD)"

exec docker-php-entrypoint "$@"
