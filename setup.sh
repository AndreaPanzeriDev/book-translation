#!/usr/bin/env bash
#
# setup.sh — prepara da zero e avvia Book Translation Studio.
#
# Cosa fa, in ordine:
#   1. verifica i prerequisiti (Docker + Docker Compose v2);
#   2. crea .env da .env.example (se manca) con password del database casuali;
#   3. costruisce l'immagine dell'applicazione (PHP 8.5 + Node 22 + Composer);
#   4. avvia i container app + MariaDB;
#   5. attende che il database sia pronto e che l'entrypoint abbia installato
#      dipendenze composer/npm, generato APP_KEY ed eseguito le migrazioni;
#   6. verifica finale: migrazioni, storage link, applicazione raggiungibile.
#
# Lo script è idempotente: si può rilanciare quando si vuole.
#
# Uso:
#   ./setup.sh               # setup + avvio (prima esecuzione: alcuni minuti)
#   ./setup.sh --rebuild     # ricostruisce l'immagine senza cache
#   ./setup.sh --skip-build  # riavvia senza ricostruire l'immagine
#   ./setup.sh --reset       # elimina container E volumi (perde il database!)
#   ./setup.sh --help
#
# Variabili d'ambiente utili:
#   DB_WAIT_TIMEOUT   secondi di attesa per il database (default 180)
#   APP_WAIT_TIMEOUT  secondi di attesa per l'app (build inclusa, default 900)

set -Eeuo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

# ---------------------------------------------------------------- output ----
if [[ -t 1 ]]; then
    C_RESET=$'\033[0m' C_BOLD=$'\033[1m' C_DIM=$'\033[2m'
    C_BLUE=$'\033[34m' C_GREEN=$'\033[32m' C_YELLOW=$'\033[33m' C_RED=$'\033[31m'
else
    C_RESET='' C_BOLD='' C_DIM='' C_BLUE='' C_GREEN='' C_YELLOW='' C_RED=''
fi

step() { printf '\n%s==> %s%s\n' "${C_BLUE}${C_BOLD}" "$*" "$C_RESET"; }
info() { printf '    %s\n' "$*"; }
ok()   { printf '    %s%s%s %s\n' "$C_GREEN" "✔" "$C_RESET" "$*"; }
warn() { printf '    %s%s%s %s\n' "$C_YELLOW" "!" "$C_RESET" "$*"; }
die()  { printf '\n%s✖ %s%s\n\n' "${C_RED}${C_BOLD}" "$*" "$C_RESET" >&2; exit 1; }

DB_WAIT_TIMEOUT="${DB_WAIT_TIMEOUT:-180}"
APP_WAIT_TIMEOUT="${APP_WAIT_TIMEOUT:-900}"

TMP_FILES=()
cleanup() {
    local f
    if ((${#TMP_FILES[@]} > 0)); then
        for f in "${TMP_FILES[@]}"; do rm -f -- "$f"; done
    fi
    return 0
}
trap cleanup EXIT
trap 'die "Comando fallito (riga $LINENO): $BASH_COMMAND"' ERR

usage() {
    awk 'NR == 1 { next } /^#/ { sub(/^# ?/, ""); print; next } { exit }' "${BASH_SOURCE[0]}"
}

# ------------------------------------------------------------------ args ----
REBUILD=0
SKIP_BUILD=0
RESET=0

while (($# > 0)); do
    case "$1" in
        --rebuild)    REBUILD=1 ;;
        --skip-build) SKIP_BUILD=1 ;;
        --reset)      RESET=1 ;;
        -h|--help)    usage; exit 0 ;;
        *)            die "Opzione sconosciuta: $1 (usa --help)" ;;
    esac
    shift
done

# ------------------------------------------------------------ prerequisiti --
step "Verifica prerequisiti"

command -v docker >/dev/null 2>&1 \
    || die "Docker non è installato. Installa Docker Desktop o Docker Engine e riprova."
docker info >/dev/null 2>&1 \
    || die "Il daemon Docker non risponde. Avvia Docker e riprova."
docker compose version >/dev/null 2>&1 \
    || die "Docker Compose v2 non è disponibile (serve il plugin 'docker compose')."
ok "Docker $(docker version --format '{{.Server.Version}}' 2>/dev/null || echo 'ok') e Compose pronti"

[[ -f Dockerfile && -f compose.yaml && -f .env.example ]] \
    || die "Esegui lo script dalla root del progetto (Dockerfile, compose.yaml e .env.example devono esistere)."

# compose.yaml usa ${UID}/${GID} per far girare i processi con il tuo utente:
# così i file generati (storage, vendor, node_modules) non appartengono a root.
# Nota: in bash UID è una variabile readonly, quindi va solo esportata.
export UID
export GID="${GID:-$(id -g)}"

# --------------------------------------------------------------- helper -----
rand_secret() {
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -hex 16
    elif [[ -r /dev/urandom ]]; then
        od -An -N16 -tx1 /dev/urandom | tr -d ' \n'
    else
        date +%s%N
    fi
}

env_get() {
    # env_get CHIAVE [default] — legge il primo valore di CHIAVE da .env
    local key="$1" fallback="${2:-}" line
    line="$(grep -E "^${key}=" .env 2>/dev/null | head -n 1 || true)"
    [[ -z "$line" ]] && { printf '%s' "$fallback"; return 0; }
    line="${line#*=}"
    line="${line%$'\r'}"
    line="${line%\"}"; line="${line#\"}"
    line="${line%\'}"; line="${line#\'}"
    printf '%s' "$line"
}

env_set() {
    # env_set CHIAVE VALORE — imposta o aggiunge una variabile in .env
    local key="$1" value="$2" tmp
    tmp="$(mktemp)"; TMP_FILES+=("$tmp")
    KEY="$key" VALUE="$value" awk '
        BEGIN { k = ENVIRON["KEY"]; found = 0 }
        $0 ~ ("^" k "=") { if (!found) { print k "=" ENVIRON["VALUE"]; found = 1 }; next }
        { print }
        END { if (!found) print k "=" ENVIRON["VALUE"] }
    ' .env > "$tmp"
    cat "$tmp" > .env
    rm -f "$tmp"
}

app_key() {
    # Chiave nel formato atteso da Laravel: base64 di 32 byte casuali.
    printf 'base64:'
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -base64 32 | tr -d '\n'
    elif [[ -r /dev/urandom ]]; then
        head -c 32 /dev/urandom | base64 | tr -d '\n'
    else
        printf '%s' "$(date +%s%N)$(rand_secret)" | base64 | tr -d '\n'
    fi
}

port_listening() {
    (exec 3<>"/dev/tcp/127.0.0.1/$1") >/dev/null 2>&1
}

http_code() {
    # http_code URL [PORTA] — codice HTTP, 000 se non risponde.
    # Qualsiasi codice (anche 500) significa che il server è in ascolto.
    local code=''
    if command -v curl >/dev/null 2>&1; then
        code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 8 "$1" 2>/dev/null || true)"
    elif port_listening "${2:-8000}"; then
        code='200'
    fi
    printf '%s' "${code:-000}"
}

listening() { [[ "$(http_code "$1" "${2:-8000}")" != "000" ]]; }

app_container() { docker compose ps -q app 2>/dev/null || true; }

app_running() {
    local cid
    cid="$(app_container)"
    [[ -n "$cid" ]] && [[ "$(docker inspect -f '{{.State.Running}}' "$cid" 2>/dev/null || echo false)" == "true" ]]
}

dump_logs() {
    printf '\n%s--- ultime righe di "docker compose logs app" ---%s\n' "$C_DIM" "$C_RESET" >&2
    docker compose logs --tail 60 app >&2 || true
    printf '%s---------------------------------------------------%s\n\n' "$C_DIM" "$C_RESET" >&2
}

dump_laravel_log() {
    printf '\n%s--- ultime righe di storage/logs/laravel.log ---%s\n' "$C_DIM" "$C_RESET" >&2
    docker compose exec -T app sh -c 'tail -n 40 storage/logs/laravel.log 2>/dev/null' >&2 || true
    printf '%s-----------------------------------------------------%s\n\n' "$C_DIM" "$C_RESET" >&2
}

# ------------------------------------------------------------------.env -----
step "Configurazione di .env"

if [[ ! -f .env ]]; then
    cp .env.example .env
    env_set DB_CONNECTION mysql
    env_set DB_HOST db
    env_set DB_PORT 3306
    env_set DB_DATABASE book_translation
    env_set DB_USERNAME book_translation
    env_set DB_PASSWORD "$(rand_secret)"
    env_set DB_ROOT_PASSWORD "$(rand_secret)"
    ok ".env creato da .env.example con password del database generate"
else
    info ".env già presente: valori esistenti mantenuti"
fi

# Chiavi usate da compose.yaml / entrypoint.sh: se mancano, aggiungile con i default.
[[ -n "$(env_get DB_CONNECTION)" ]] || env_set DB_CONNECTION mysql
[[ -n "$(env_get DB_HOST)" ]]       || env_set DB_HOST db
[[ -n "$(env_get DB_PORT)" ]]       || env_set DB_PORT 3306
[[ -n "$(env_get DB_DATABASE)" ]]   || env_set DB_DATABASE book_translation
[[ -n "$(env_get DB_USERNAME)" ]]   || env_set DB_USERNAME book_translation
[[ -n "$(env_get DB_PASSWORD)" ]]   || env_set DB_PASSWORD "$(rand_secret)"
[[ -n "$(env_get DB_ROOT_PASSWORD)" ]] || env_set DB_ROOT_PASSWORD root_change_me
[[ -n "$(env_get APP_URL)" ]]       || env_set APP_URL http://localhost:8000

# APP_KEY va scritta PRIMA di avviare i container: le variabili d'ambiente
# hanno precedenza sul file .env, quindi un key generato a container avviato
# verrebbe ignorato (Laravel: "No application encryption key has been specified").
if [[ "$(env_get APP_KEY)" != base64:* ]]; then
    env_set APP_KEY "$(app_key)"
    ok "APP_KEY generata in .env"
else
    info "APP_KEY già presente in .env"
fi

DB_CONNECTION="$(env_get DB_CONNECTION mysql)"
DB_HOST="$(env_get DB_HOST db)"
DB_DATABASE="$(env_get DB_DATABASE book_translation)"
DB_USERNAME="$(env_get DB_USERNAME book_translation)"
DB_EXTERNAL_PORT="$(env_get DB_EXTERNAL_PORT 3307)"

if [[ "$DB_CONNECTION" == "mysql" || "$DB_CONNECTION" == "mariadb" ]]; then
    case "$DB_HOST" in
        127.0.0.1|localhost)
            die "DB_HOST=$DB_HOST non è raggiungibile dall'interno del container.
   Imposta DB_HOST=db in .env per usare il MariaDB di compose.yaml."
            ;;
    esac
fi
ok "Database: $DB_DATABASE su $DB_HOST:$DB_EXTERNAL_PORT (esterno)"

chmod +x docker/entrypoint.sh 2>/dev/null || true

# ----------------------------------------------------- porte già occupate ---
step "Controllo porte 8000 e 5173"

if app_running; then
    info "Lo stack risulta già in esecuzione: verrà riavviato"
else
    for port in 8000 5173; do
        if port_listening "$port"; then
            die "La porta $port è già occupata da un altro processo.
   Fermalo (es. 'sudo ss -ltnp | grep :$port') oppure cambia le porte in compose.yaml."
        fi
    done
    ok "Porte libere"
fi

# --------------------------------------------------------------- docker -----
if [[ $RESET -eq 1 ]]; then
    step "Reset: arresto container e cancellazione volumi"
    if [[ -t 0 ]]; then
        read -r -p "    Verrà cancellato il database (volume db-data). Continuare? [y/N] " answer
        [[ "$answer" =~ ^[Yy]$ ]] || die "Operazione annullata."
    fi
    docker compose down --volumes --remove-orphans --timeout 20 || true
    ok "Volume del database rimosso"
fi

if [[ $SKIP_BUILD -eq 0 ]]; then
    step "Build dell'immagine Docker (PHP 8.5 + Node 22)"
    if [[ $REBUILD -eq 1 ]]; then
        docker compose build --no-cache app
    else
        docker compose build app
    fi
    ok "Immagine aggiornata"
else
    step "Build dell'immagine Docker"
    info "saltata (--skip-build)"
fi

step "Avvio dei container"
docker compose up -d --remove-orphans
ok "Container avviati"

# ------------------------------------------------------------ attesa DB -----
step "Attesa del database ($DB_WAIT_TIMEOUT s max)"
deadline=$(( $(date +%s) + DB_WAIT_TIMEOUT ))
db_ready=0
while (( $(date +%s) < deadline )); do
    db_cid="$(docker compose ps -q db 2>/dev/null || true)"
    if [[ -n "$db_cid" ]]; then
        status="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$db_cid" 2>/dev/null || echo unknown)"
        if [[ "$status" == "healthy" ]]; then
            db_ready=1
            break
        fi
    fi
    printf '.'
    sleep 2
done
[[ $db_ready -eq 1 ]] || { printf '\n'; dump_logs; die "Il database non è diventato pronto entro ${DB_WAIT_TIMEOUT}s."; }
printf '\n'
ok "Database pronto"

# ------------------------------------------------------- attesa applicazione -
step "Attesa dell'applicazione (alla prima esecuzione installa composer/npm: può richiedere alcuni minuti)"
info "segui l'avanzamento con: docker compose logs -f app"
info "timeout: ${APP_WAIT_TIMEOUT}s"
deadline=$(( $(date +%s) + APP_WAIT_TIMEOUT ))
app_ready=0
while (( $(date +%s) < deadline )); do
    if ! app_running; then
        printf '\n'
        dump_logs
        die "Il container dell'applicazione si è fermato. Vedi i log qui sopra."
    fi
    if listening "http://127.0.0.1:8000/" 8000; then
        app_ready=1
        break
    fi
    printf '.'
    sleep 3
done
[[ $app_ready -eq 1 ]] || { printf '\n'; dump_logs; die "L'applicazione non ha risposto entro ${APP_WAIT_TIMEOUT}s."; }
printf '\n'
ok "Server Laravel in ascolto su http://localhost:8000"

vite_ready=0
for _ in $(seq 1 20); do
    if [[ "$(http_code http://127.0.0.1:5173/@vite/client 5173)" == 2* ]]; then
        vite_ready=1
        break
    fi
    sleep 1
done
if [[ $vite_ready -eq 1 ]]; then
    ok "Vite/HMR raggiungibile su http://localhost:5173"
else
    warn "Vite (porta 5173) non risponde ancora: il primo avvio può richiedere qualche secondo"
fi

# ------------------------------------------------------ verifica finale -----
step "Verifica database, migrazioni e storage"
docker compose exec -T app php artisan migrate --force --no-interaction
docker compose exec -T app php artisan storage:link >/dev/null 2>&1 || true
docker compose exec -T app php artisan optimize:clear >/dev/null 2>&1 || true
ok "Migrazioni applicate"
ok "Storage link e cache allineati"

if ! docker compose exec -T app sh -c 'grep -q "^APP_KEY=base64:" .env'; then
    die "APP_KEY non generata: controlla i log con 'docker compose logs app'."
fi
ok "APP_KEY presente e caricata"

step "Verifica della pagina iniziale"
http_status='000'
for _ in $(seq 1 10); do
    http_status="$(http_code http://127.0.0.1:8000/ 8000)"
    [[ "$http_status" == 2* || "$http_status" == 3* ]] && break
    sleep 3
done
case "$http_status" in
    2*|3*)
        ok "La pagina http://localhost:8000 risponde (HTTP $http_status)"
        ;;
    000)
        dump_logs
        die "Nessuna risposta su http://localhost:8000."
        ;;
    *)
        dump_laravel_log
        die "L'applicazione risponde HTTP $http_status invece di una pagina valida."
        ;;
esac

# --------------------------------------------------------------- riepilogo --
cat <<EOF

${C_GREEN}${C_BOLD}✔ Book Translation Studio è pronto${C_RESET}

  Applicazione   ${C_BOLD}http://localhost:8000${C_RESET}
  Vite (HMR)     ${C_BOLD}http://localhost:5173${C_RESET}
  Database       ${DB_DATABASE} su ${C_BOLD}127.0.0.1:${DB_EXTERNAL_PORT}${C_RESET} (utente ${DB_USERNAME})

  Primo passo: apri ${C_BOLD}Account AI → Nuovo account${C_RESET} e inserisci un provider
  (OpenAI, OpenRouter, Ollama o endpoint compatibile OpenAI).

  Comandi utili
    docker compose logs -f app      log di Laravel e Vite
    docker compose exec app bash    shell nel container
    make down                       arresta tutto
    ./setup.sh                      riallinea e riavvia (idempotente)
    ./setup.sh --reset              ricrea da zero anche il database

  Ollama locale: usa l'URL http://host.docker.internal:11434/v1 e avvia il server
  in ascolto sull'host, ad esempio:  OLLAMA_HOST=0.0.0.0:11434 ollama serve

EOF
