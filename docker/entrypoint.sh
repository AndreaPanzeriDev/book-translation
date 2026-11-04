#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"
export COMPOSER_HOME="${COMPOSER_HOME:-/home/app/.composer}"
export npm_config_cache="${npm_config_cache:-/home/app/.npm}"

# Porte configurate in .env e passate da compose.yaml. Lo stesso numero è
# usato dentro e fuori dal container.
APP_PORT="${APP_PORT:-8000}"
VITE_PORT="${VITE_PORT:-5173}"
export APP_PORT VITE_PORT

if [ ! -f .env ]; then
    cp .env.example .env
fi

if ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --force --no-interaction
fi

mkdir -p database storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/public storage/app/private/books
touch database/database.sqlite

if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist
fi

if [ ! -x node_modules/.bin/vite ]; then
    npm install
fi

if ! php artisan migrate --force --no-interaction; then
    cat >&2 <<'MSG'

Migrazione fallita. Se l'errore è "Access denied for user ...", le credenziali
in .env non corrispondono a quelle con cui è stato inizializzato il volume del
database (MariaDB le applica solo alla prima creazione del volume).

Dalla root del progetto esegui:

    ./setup.sh --reset

per ricreare il database (i dati esistenti andranno persi), oppure allinea
DB_USERNAME/DB_PASSWORD/DB_ROOT_PASSWORD in .env alle credenziali esistenti.
MSG
    exit 1
fi
php artisan storage:link >/dev/null 2>&1 || true

rm -f public/hot
npm run dev -- --host 0.0.0.0 --port "$VITE_PORT" &
VITE_PID=$!

# Attende che Vite abbia scritto public/hot prima di avviare Laravel.
for _ in $(seq 1 100); do
    if [ -f public/hot ]; then
        break
    fi
    sleep 0.2
done

php artisan serve --host=0.0.0.0 --port="$APP_PORT" --no-reload &
PHP_PID=$!

cleanup() {
    kill "${VITE_PID}" "${PHP_PID}" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

wait -n "${VITE_PID}" "${PHP_PID}"
