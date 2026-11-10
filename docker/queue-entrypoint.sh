#!/usr/bin/env bash
#
# queue-entrypoint.sh — avvia i worker Laravel della coda "translations".
#
# Il container queue usa la stessa immagine e lo stesso bind mount dell'app.
# Attende che l'app abbia creato .env, installato vendor e applicato le
# migrazioni, poi avvia QUEUE_WORKERS processi queue:work indipendenti.
#
# Variabili (da .env o da compose.yaml):
#   QUEUE_WORKERS             numero di worker (default 4)
#   QUEUE_JOB_TIMEOUT         timeout di un singolo job in secondi (default 360)
#   QUEUE_WORKER_MAX_TIME     riavvio periodico del worker in secondi (default 3600)
#   QUEUE_WORKER_MAX_JOBS     job massimi per worker prima del riavvio (default 500)

set -Eeuo pipefail

cd /var/www/html

export COMPOSER_HOME="${COMPOSER_HOME:-/home/app/.composer}"

QUEUE_WORKERS="${QUEUE_WORKERS:-4}"
QUEUE_JOB_TIMEOUT="${QUEUE_JOB_TIMEOUT:-360}"
QUEUE_WORKER_MAX_TIME="${QUEUE_WORKER_MAX_TIME:-3600}"
QUEUE_WORKER_MAX_JOBS="${QUEUE_WORKER_MAX_JOBS:-500}"

if ! [[ "$QUEUE_WORKERS" =~ ^[0-9]+$ ]] || (( QUEUE_WORKERS < 1 )); then
    echo "[queue] QUEUE_WORKERS non valido ($QUEUE_WORKERS): uso 4." >&2
    QUEUE_WORKERS=4
fi

echo "[queue] attendo .env e APP_KEY…"

for _ in $(seq 1 150); do
    if [ -f .env ] && grep -q '^APP_KEY=base64:' .env; then
        break
    fi
    sleep 2
done

if ! { [ -f .env ] && grep -q '^APP_KEY=base64:' .env; }; then
    echo "[queue] APP_KEY non trovata in .env: controlla i log di app." >&2
    exit 1
fi

echo "[queue] attendo vendor/ (installato dal container app)…"

for _ in $(seq 1 600); do
    if [ -f vendor/autoload.php ]; then
        break
    fi
    sleep 2
done

if [ ! -f vendor/autoload.php ]; then
    echo "[queue] vendor/autoload.php non trovato: controlla i log di app." >&2
    exit 1
fi

echo "[queue] attendo database e migrazioni…"

# --pending esce con codice 0 solo quando tutte le migrazioni sono applicate:
# evita di avviare i worker mentre l'app sta ancora creando le tabelle.
for _ in $(seq 1 150); do
    if php artisan migrate:status --pending --no-interaction >/dev/null 2>&1; then
        break
    fi
    sleep 2
done

if ! php artisan migrate:status --pending --no-interaction >/dev/null 2>&1; then
    echo "[queue] database o migrazioni non disponibili: controlla i log di app." >&2
    exit 1
fi

pids=()
cleanup() {
    trap - EXIT INT TERM
    if ((${#pids[@]} > 0)); then
        kill -TERM "${pids[@]}" 2>/dev/null || true
        wait "${pids[@]}" 2>/dev/null || true
    fi
}
trap cleanup EXIT INT TERM

for ((i = 1; i <= QUEUE_WORKERS; i++)); do
    php artisan queue:work database \
        --queue=translations,default \
        --sleep=1 \
        --tries=3 \
        --timeout="$QUEUE_JOB_TIMEOUT" \
        --max-time="$QUEUE_WORKER_MAX_TIME" \
        --max-jobs="$QUEUE_WORKER_MAX_JOBS" \
        --no-interaction &
    pids+=("$!")
    echo "[queue] worker $i avviato (pid ${pids[-1]})"
done

status=0
wait -n "${pids[@]}" || status=$?
echo "[queue] un worker è terminato (exit $status): riavvio del container."
exit "$status"
