#!/usr/bin/env bash
#
# setup.sh — prepara da zero e avvia Book Translation Studio.
#
# Cosa fa, in ordine:
#   1. verifica i prerequisiti (Docker + Docker Compose v2);
#   2. crea .env da .env.example (se manca) con password del database casuali;
#   3. verifica le porte definite in .env e, se occupate, ne propone altre;
#   4. costruisce l'immagine dell'applicazione (PHP 8.5 + Node 22 + Composer);
#   5. avvia i container app + MariaDB;
#   6. attende che il database sia pronto, verifica che le credenziali di .env
#      siano valide e, se il volume è di un'installazione precedente, propone
#      di ricrearlo;
#   7. attende che l'entrypoint abbia installato dipendenze composer/npm,
#      generato APP_KEY ed eseguito le migrazioni;
#   8. verifica finale: migrazioni, storage link, applicazione raggiungibile.
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
# Le porte sono definite solo in .env:
#   APP_PORT            porta dell'applicazione (default 8000)
#   VITE_PORT           porta di Vite/HMR (default 5173)
#   DB_EXTERNAL_PORT    porta di MariaDB per client esterni (default 3307)
# Se una porta è occupata, lo script propone un'alternativa e la salva in .env.
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
# Sentinella: evita l'espansione di un array vuoto con `set -u` su bash vecchi.
CHOSEN_PORTS=(-1)
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
    local port="$1"
    if command -v ss >/dev/null 2>&1; then
        if ss -ltn 2>/dev/null \
            | awk -v suffix=":${port}" '$4 ~ (suffix "$") { found = 1 } END { exit(found ? 0 : 1) }'; then
            return 0
        fi
    fi
    if command -v lsof >/dev/null 2>&1; then
        if lsof -nP -iTCP:"${port}" -sTCP:LISTEN >/dev/null 2>&1; then
            return 0
        fi
    fi
    (exec 3<>"/dev/tcp/127.0.0.1/${port}") >/dev/null 2>&1
}

port_owner() {
    # Nome del processo in ascolto, se individuabile (best effort).
    local port="$1" name=''
    if command -v lsof >/dev/null 2>&1; then
        name="$(lsof -nP -iTCP:"${port}" -sTCP:LISTEN -F c 2>/dev/null | sed -n 's/^c//p' | head -n 1 || true)"
    fi
    if [[ -z "$name" ]] && command -v ss >/dev/null 2>&1; then
        name="$(ss -ltnp 2>/dev/null \
            | awk -v suffix=":${port}" '$4 ~ (suffix "$")' \
            | sed -n 's/.*users:(("\([^"]*\)".*/\1/p' \
            | head -n 1 || true)"
    fi
    printf '%s' "$name"
}

port_used_by_project() {
    # true se la porta è pubblicata da un container di questo progetto.
    local port="$1" ports
    ports="$(docker compose ps --format '{{.Ports}}' 2>/dev/null || true)"
    [[ "$ports" == *"127.0.0.1:${port}->"* ]]
}

valid_port() {
    local port="$1"
    [[ "$port" =~ ^[1-9][0-9]{3,4}$ ]] || return 1
    (( port >= 1024 && port <= 65535 ))
}

port_already_chosen() {
    local candidate="$1" chosen
    for chosen in "${CHOSEN_PORTS[@]}"; do
        [[ "$chosen" == "$candidate" ]] && return 0
    done
    return 1
}

next_free_port() {
    local candidate="$1" i
    for ((i = 0; i < 200; i++)); do
        candidate=$((candidate + 1))
        if ((candidate > 65535)); then
            candidate=1024
        fi
        port_already_chosen "$candidate" && continue
        port_listening "$candidate" && continue
        printf '%s' "$candidate"
        return 0
    done
    return 1
}

resolve_port() {
    # resolve_port CHIAVE DEFAULT ETICHETTA
    # Legge la porta da .env, verifica che sia libera e, se occupata, chiede
    # (o sceglie automaticamente) un'alternativa salvandola in .env.
    local key="$1" default="$2" label="$3"
    local original current suggested answer owner

    original="$(env_get "$key" "$default")"
    current="$original"

    if ! valid_port "$current"; then
        warn "Valore non valido per $key in .env ('$current'): uso $default"
        current="$default"
    fi

    while :; do
        if port_already_chosen "$current"; then
            warn "La porta $current ($label) è già assegnata a un altro servizio."
        elif ! port_used_by_project "$current" && port_listening "$current"; then
            owner="$(port_owner "$current")"
            if [[ -n "$owner" ]]; then
                warn "La porta $current ($label) è già occupata da: $owner"
            else
                warn "La porta $current ($label) è già occupata da un altro processo."
            fi
        else
            break
        fi

        suggested="$(next_free_port "$current")" || die "Nessuna porta libera trovata per $label."

        if [[ -t 0 ]]; then
            while :; do
                read -r -p "    Nuova porta per $label [$suggested] (Invio per accettare, q per uscire): " answer || answer='q'
                answer="${answer:-$suggested}"
                if [[ "$answer" == "q" || "$answer" == "Q" ]]; then
                    die "Operazione annullata."
                fi
                if ! valid_port "$answer"; then
                    warn "Porta non valida: usa un numero tra 1024 e 65535."
                    continue
                fi
                if port_already_chosen "$answer"; then
                    warn "La porta $answer è già assegnata a un altro servizio."
                    continue
                fi
                if port_listening "$answer"; then
                    warn "La porta $answer è occupata."
                    continue
                fi
                break
            done
        else
            answer="$suggested"
            warn "Nessun terminale interattivo: uso la porta $answer per $label."
        fi

        current="$answer"
    done

    CHOSEN_PORTS+=("$current")
    if [[ "$current" != "$original" ]]; then
        env_set "$key" "$current"
        ok "Porta $current per $label salvata in .env ($key)"
    fi
    printf -v "$key" '%s' "$current"
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

db_query() {
    # db_query UTENTE PASSWORD SQL [DATABASE] — esegue una query sul MariaDB
    # di compose. MYSQL_PWD evita di passare la password sulla riga di comando.
    local user="$1" password="$2" sql="$3" database="${4:-}"
    if [[ -n "$database" ]]; then
        docker compose exec -T db \
            env MYSQL_PWD="$password" mariadb --protocol=tcp -h 127.0.0.1 \
            -u "$user" -D "$database" -N -B -e "$sql" 2>/dev/null
    else
        docker compose exec -T db \
            env MYSQL_PWD="$password" mariadb --protocol=tcp -h 127.0.0.1 \
            -u "$user" -N -B -e "$sql" 2>/dev/null
    fi
}

db_credentials_ok() {
    db_query "$1" "$2" 'SELECT 1' "${3:-}" >/dev/null 2>&1
}

wait_for_db() {
    local deadline=$(( $(date +%s) + DB_WAIT_TIMEOUT )) db_cid status
    while (( $(date +%s) < deadline )); do
        db_cid="$(docker compose ps -q db 2>/dev/null || true)"
        if [[ -n "$db_cid" ]]; then
            status="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$db_cid" 2>/dev/null || echo unknown)"
            if [[ "$status" == "healthy" ]]; then
                return 0
            fi
        fi
        printf '.'
        sleep 2
    done
    return 1
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
[[ -n "$(env_get APP_PORT)" ]]          || env_set APP_PORT 8000
[[ -n "$(env_get VITE_PORT)" ]]         || env_set VITE_PORT 5173
[[ -n "$(env_get DB_EXTERNAL_PORT)" ]]  || env_set DB_EXTERNAL_PORT 3307

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
DB_PORT="$(env_get DB_PORT 3306)"
DB_DATABASE="$(env_get DB_DATABASE book_translation)"
DB_USERNAME="$(env_get DB_USERNAME book_translation)"
DB_PASSWORD="$(env_get DB_PASSWORD)"
DB_ROOT_PASSWORD="$(env_get DB_ROOT_PASSWORD)"

# Compose dà precedenza alle variabili già esportate nella shell rispetto a
# .env: senza questo export un DB_PASSWORD rimasto da un altro progetto
# inizializzerebbe MariaDB con una password diversa da quella letta da Laravel
# (che usa il .env montato nel container), causando "Access denied".
export DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD DB_ROOT_PASSWORD

if [[ "$DB_CONNECTION" == "mysql" || "$DB_CONNECTION" == "mariadb" ]]; then
    case "$DB_HOST" in
        127.0.0.1|localhost)
            die "DB_HOST=$DB_HOST non è raggiungibile dall'interno del container.
   Imposta DB_HOST=db in .env per usare il MariaDB di compose.yaml."
            ;;
    esac
fi
ok "Database: $DB_DATABASE su $DB_HOST"

chmod +x docker/entrypoint.sh 2>/dev/null || true

# ----------------------------------------------------- configurazione porte -
step "Configurazione delle porte"

if app_running; then
    info "Lo stack risulta già in esecuzione: le sue porte non sono considerate un conflitto"
fi

CHOSEN_PORTS=(-1)
resolve_port APP_PORT 8000 "applicazione Laravel"
resolve_port VITE_PORT 5173 "Vite/HMR"
resolve_port DB_EXTERNAL_PORT 3307 "MariaDB (accesso esterno)"

# APP_URL segue APP_PORT: aggiorna solo gli URL locali. Un eventuale dominio
# personalizzato configurato dall'utente viene lasciato intatto.
# La forma con ${APP_PORT} è migrata dai setup precedenti.
current_app_url="$(env_get APP_URL)"
if [[ -z "$current_app_url" \
    || "$current_app_url" == 'http://localhost:${APP_PORT}' \
    || "$current_app_url" =~ ^http://(localhost|127\.0\.0\.1)(:[0-9]+)?/?$ ]]; then
    env_set APP_URL "http://localhost:${APP_PORT}"
fi

# Esporta i valori verificati: compose usa queste porte/URL anche se la shell
# contiene valori diversi.
APP_URL="$(env_get APP_URL)"
export APP_PORT VITE_PORT DB_EXTERNAL_PORT APP_URL
ok "Applicazione: http://localhost:$APP_PORT · Vite: http://localhost:$VITE_PORT · MariaDB esterno: 127.0.0.1:$DB_EXTERNAL_PORT"

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
docker compose up -d --remove-orphans \
    || die "Avvio dei container fallito. Se il messaggio segnala una porta occupata, rilancia ./setup.sh: ne proporrà una libera."
ok "Container avviati"

# ------------------------------------------------------------ attesa DB -----
step "Attesa del database ($DB_WAIT_TIMEOUT s max)"
if wait_for_db; then
    printf '\n'
    ok "Database pronto"
else
    printf '\n'
    dump_logs
    die "Il database non è diventato pronto entro ${DB_WAIT_TIMEOUT}s."
fi

# --------------------------------------------------- credenziali del DB -----
# MariaDB applica MYSQL_PASSWORD/MYSQL_ROOT_PASSWORD solo quando il volume
# db-data viene creato. Se il volume esiste già con altre credenziali
# (reinstallazione, .env rigenerato, DB_PASSWORD esportata nella shell),
# Laravel non riesce ad autenticarsi e l'entrypoint si ferma sulle migrazioni.
step "Verifica delle credenziali del database"

if [[ "$DB_CONNECTION" != "mysql" && "$DB_CONNECTION" != "mariadb" ]] || [[ "$DB_HOST" != "db" ]]; then
    info "Controllo saltato: il database non è quello gestito da compose.yaml"
elif db_credentials_ok "$DB_USERNAME" "$DB_PASSWORD" "$DB_DATABASE"; then
    ok "L'utente $DB_USERNAME accede al database $DB_DATABASE"
else
    warn "Il database non accetta le credenziali presenti in .env."
    if db_credentials_ok root "$DB_ROOT_PASSWORD"; then
        warn "Le credenziali root funzionano: DB_PASSWORD non coincide con quella del volume."
    else
        warn "Neanche le credenziali root coincidono: il volume db-data appartiene a un'altra installazione."
    fi
    warn "MariaDB usa DB_PASSWORD/DB_ROOT_PASSWORD solo alla prima creazione del volume."

    if [[ $RESET -eq 1 ]]; then
        die "Il database appena ricreato rifiuta ancora le credenziali di .env: controlla DB_* in .env."
    fi

    if [[ -t 0 ]]; then
        read -r -p "    Ricreare il database? I dati esistenti andranno persi. [y/N] " answer
        if [[ "$answer" =~ ^[Yy]$ ]]; then
            docker compose down --volumes --remove-orphans --timeout 20 \
                || die "Arresto dei container fallito."
            docker compose up -d --remove-orphans \
                || die "Avvio dei container fallito dopo il reset del database."
            if ! wait_for_db; then
                printf '\n'
                dump_logs
                die "Il database non è diventato pronto dopo il reset."
            fi
            printf '\n'
            ok "Database ricreato"
            db_credentials_ok "$DB_USERNAME" "$DB_PASSWORD" "$DB_DATABASE" \
                || die "Il database ricreato rifiuta ancora le credenziali di .env. Controlla DB_* in .env."
            ok "L'utente $DB_USERNAME accede al database $DB_DATABASE"
        else
            die "Allinea DB_USERNAME/DB_PASSWORD/DB_ROOT_PASSWORD in .env al volume esistente, oppure rilancia ./setup.sh --reset."
        fi
    else
        die "Rilancia ./setup.sh --reset per ricreare il database (i dati esistenti andranno persi)."
    fi
fi

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
    if listening "http://127.0.0.1:${APP_PORT}/" "$APP_PORT"; then
        app_ready=1
        break
    fi
    printf '.'
    sleep 3
done
[[ $app_ready -eq 1 ]] || { printf '\n'; dump_logs; die "L'applicazione non ha risposto entro ${APP_WAIT_TIMEOUT}s."; }
printf '\n'
ok "Server Laravel in ascolto su http://localhost:${APP_PORT}"

vite_ready=0
for _ in $(seq 1 20); do
    if [[ "$(http_code "http://127.0.0.1:${VITE_PORT}/@vite/client" "$VITE_PORT")" == 2* ]]; then
        vite_ready=1
        break
    fi
    sleep 1
done
if [[ $vite_ready -eq 1 ]]; then
    ok "Vite/HMR raggiungibile su http://localhost:${VITE_PORT}"
else
    warn "Vite (porta ${VITE_PORT}) non risponde ancora: il primo avvio può richiedere qualche secondo"
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
    http_status="$(http_code "http://127.0.0.1:${APP_PORT}/" "$APP_PORT")"
    [[ "$http_status" == 2* || "$http_status" == 3* ]] && break
    sleep 3
done
case "$http_status" in
    2*|3*)
        ok "La pagina http://localhost:${APP_PORT} risponde (HTTP $http_status)"
        ;;
    000)
        dump_logs
        die "Nessuna risposta su http://localhost:${APP_PORT}."
        ;;
    *)
        dump_laravel_log
        die "L'applicazione risponde HTTP $http_status invece di una pagina valida."
        ;;
esac

# --------------------------------------------------------------- riepilogo --
cat <<EOF

${C_GREEN}${C_BOLD}✔ Book Translation Studio è pronto${C_RESET}

  Applicazione   ${C_BOLD}http://localhost:${APP_PORT}${C_RESET}
  Vite (HMR)     ${C_BOLD}http://localhost:${VITE_PORT}${C_RESET}
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
