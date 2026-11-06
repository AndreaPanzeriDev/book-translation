# Book Translation Studio

Applicazione web locale per importare EPUB, tradurli con uno o più account AI e confrontare l'originale con una o due traduzioni affiancate, così da valutare la qualità con i propri occhi.

Lo stack è **Laravel 13 + Inertia 3 + React 19 + TypeScript + Tailwind CSS 4 + Ant Design 6**, interamente dentro Docker. Non serve PHP, Node o un database installato sulla macchina host.

## Caratteristiche

- Importazione di EPUB 2/3 con metadati, copertina, indice e capitoli.
- Pagine virtuali stabili da circa 1.500 caratteri, perché l'EPUB non ha pagine fisse.
- Selezione di una pagina, di un intervallo con `Shift + clic`, di un intero capitolo o di tutto il libro.
- Confronto originale + 1 traduzione oppure originale + 2 traduzioni (massimo tre colonne).
- Ogni colonna di traduzione sceglie autonomamente account e modello.
- Più account contemporanei: OpenAI, OpenRouter, Ollama locale o qualsiasi endpoint compatibile OpenAI.
- Traduzione in batch eseguita sul server: un batch attivo per libro, libri diversi in parallelo, avanzamento persistente anche dopo refresh o chiusura del tab. Pannello globale con interruzione e riprova degli errori.
- Cache delle traduzioni per pagina, hash del testo, account, modello, lingua e versione del prompt: cambiare colonna è istantaneo quando la traduzione esiste già.
- Allineamento a livello di frase: selezionando una o più frasi nell'originale, le frasi corrispondenti si evidenziano automaticamente in tutte le traduzioni visibili, anche quelle già in cache.
- Chiavi API cifrate nel database e mai serializzate verso il browser.
- Lingua di destinazione iniziale: italiano, modificabile e salvata per libro. Lingua di origine rilevata dall'EPUB.
- Interfaccia in italiano e inglese, senza login e pensata per un solo utente locale. All’apertura usa la lingua del browser (italiano se non riconosciuta); il selettore IT/EN la salva in un cookie per un anno. Anche validazioni, messaggi flash ed errori dei batch seguono la lingua scelta.

## Requisiti

- Docker con Docker Compose.
- Almeno 2 GB di RAM liberi per il container (i modelli AI girano fuori, sul provider scelto).
- Connessione internet per i provider remoti; nessuna connessione per Ollama locale.

## Avvio rapido

```bash
./setup.sh
```

Lo script fa tutto: crea `.env` da `.env.example` con password del database e `APP_KEY` generate, costruisce l'immagine, avvia app, worker di traduzione e MariaDB, attende che composer/npm siano installati e che le migrazioni siano applicate, poi verifica che la pagina iniziale risponda. È idempotente: si può rilanciare quando si vuole.

Il primo avvio può richiedere qualche minuto (build dell'immagine + `composer install` + `npm install`).

| Comando | Effetto |
| --- | --- |
| `./setup.sh` | setup completo e avvio (o riavvio) |
| `./setup.sh --skip-build` | riavvia senza ricostruire l'immagine |
| `./setup.sh --rebuild` | ricostruisce l'immagine senza cache |
| `./setup.sh --reset` | elimina container **e volumi** (perde il database) e ricrea tutto |

`make up` resta disponibile come alias rapido di `docker compose up -d --build` (senza le verifiche dello script).

Aprire l'URL mostrato al termine dello script (per impostazione predefinita **<http://localhost:8000>**). Gli avvisi di setup, incluso l'esito dei controlli finali, sono stampati a terminale; in caso di errore lo script mostra le ultime righe dei log e termina con codice diverso da zero.

Le porte sono definite **solo in `.env`** e sono pubblicate esclusivamente su `127.0.0.1`:

- `APP_PORT` (default `8000`) — applicazione Laravel;
- `VITE_PORT` (default `5173`) — server di sviluppo Vite (ricarica automatica e HMR);
- `DB_EXTERNAL_PORT` (default `3307`) — MariaDB per client esterni.

Se una porta è occupata, `./setup.sh` ne propone un'altra, la salva in `.env` e la usa per tutti i servizi. Non serve modificare `compose.yaml`, `vite.config.ts` o `docker/entrypoint.sh`. Per forzare una porta diversa, modifica `APP_PORT`, `VITE_PORT` o `DB_EXTERNAL_PORT` in `.env` e rilancia `./setup.sh`: `APP_URL` viene aggiornato automaticamente.

Le traduzioni vengono eseguite dal servizio `queue`, che usa lo stesso codice e lo stesso database dell'app. Il numero di worker è configurabile con `QUEUE_WORKERS` in `.env` (default `4`). Se il servizio è fermo, i batch restano **In coda** e riprendono quando il worker torna attivo; i job sopravvivono anche a un riavvio dei container.

Per fermare tutto:

```bash
make down
```

Per ricostruire dopo modifiche a `Dockerfile` o `compose.yaml`:

```bash
docker compose up -d --build
```

## Configurazione degli account AI

Aprire **Account AI → Nuovo account**.

| Provider | URL di base tipico | Chiave |
| --- | --- | --- |
| OpenAI | `https://api.openai.com/v1` | necessaria |
| OpenRouter | `https://openrouter.ai/api/v1` | necessaria |
| Ollama (locale) | `http://host.docker.internal:11434/v1` | non necessaria |
| Compatibile OpenAI | qualsiasi URL che esponga `/v1/chat/completions` | dipende dal server |

Alla creazione l’elenco dei modelli viene importato automaticamente. Usare **Sincronizza modelli** per aggiornarlo in seguito; i modelli possono anche essere digitati manualmente nello studio, senza sincronizzazione.

Ogni account ha una **Modalità richieste**:

- **In serie** (predefinita) — quando si traduce una selezione, le pagine vengono inviate una alla volta; la successiva parte solo quando la precedente è terminata.
- **In parallelo** — tutte le pagine selezionate vengono inviate contemporaneamente, con una richiesta separata per pagina. Con due colonne di traduzione, ogni account segue la propria modalità in modo indipendente.

In parallelo le richieste vengono distribuite ai worker del servizio `queue`: con `QUEUE_WORKERS=4` si possono avere fino a 4 richieste contemporanee in totale, condivise tra i batch attivi. In serie un account esegue una sola richiesta alla volta anche se lo usano libri diversi, grazie a un lock condiviso. Per aumentare il parallelismo alzare `QUEUE_WORKERS` in `.env` e riavviare con `./setup.sh` (a costo di maggiore memoria).

Le chiavi API sono cifrate con la `APP_KEY` di Laravel e non vengono mai inviate al browser. In modifica, lasciare vuoto il campo chiave per mantenere quella esistente.

### Ollama nel container

Ollama, per impostazione predefinita, ascolta solo su `127.0.0.1:11434` e quindi **non è raggiungibile dal container**. Il file `compose.yaml` mappa già `host.docker.internal` verso l'host, ma il server Ollama deve accettare connessioni da un indirizzo raggiungibile dal container.

Su Linux, avviare Ollama in ascolto sull'interfaccia Docker:

```bash
OLLAMA_HOST=172.17.0.1:11434 ollama serve
```

In alternativa, con systemd:

```bash
sudo systemctl edit ollama
```

Aggiungere:

```ini
[Service]
Environment="OLLAMA_HOST=0.0.0.0:11434"
```

Poi:

```bash
sudo systemctl restart ollama
```

Con `OLLAMA_HOST=0.0.0.0` il server è raggiungibile anche dalla rete locale: valutare il firewall. L'URL da usare nell'app resta `http://host.docker.internal:11434/v1`.

Verificare la connessione con **Account AI → Verifica**. Se compare un errore di connessione, controllare che Ollama sia in ascolto su un indirizzo non-loopback e che la porta `11434` sia raggiungibile dall'host.

## Uso

1. **Importa un EPUB** dalla libreria. Il testo viene estratto in blocchi e raggruppato in pagine virtuali da circa 1.500 caratteri.
2. Aprire lo studio del libro e scegliere la lingua di destinazione: la scelta viene salvata sul libro e ripristinata ai caricamenti successivi.
3. Selezionare le pagine da tradurre:
   - clic sulla casella di una pagina;
   - `Shift + clic` per selezionare un intervallo;
   - **Seleziona capitolo** per il capitolo attivo;
   - **Tutto il libro** per l'intero volume.
4. Scegliere account e modello per **Traduzione 1**. Attivare **Confronta due traduzioni** per aggiungere una seconda colonna indipendente.
5. Premere **Traduci selezione** oppure **Traduci** nella singola pagina. Il batch viene creato sul server e prosegue anche se si cambia pagina, si fa refresh o si chiude il tab.
6. Seguire l’avanzamento nel pannello globale in basso a destra: mostra libri in traduzione, richieste elaborate, riuscite, errori, richieste in corso e permette di interrompere o riprovare i fallimenti. Il pannello resta visibile su Account AI, Libreria e altri libri.
7. Un libro può avere un solo batch attivo; libri diversi procedono contemporaneamente. Le traduzioni già ottenute vengono mostrate con il tag `cache` e non vengono richieste di nuovo.
8. Selezionare una frase nell'originale: la traduzione corrispondente viene evidenziata automaticamente in ogni colonna visibile.

L'originale resta sempre visibile nella prima colonna. Le colonne di traduzione possono usare lo stesso account con modelli diversi oppure account diversi.

Il selettore **IT / EN** nell’intestazione cambia la lingua dell’interfaccia: la scelta viene applicata subito e salvata in un cookie `locale` valido un anno. Senza una scelta esplicita, il server usa la lingua del browser e ripiega sull’italiano.

## Come funziona

- **Importazione** — `EpubParser` legge `container.xml`, il package OPF, l'indice EPUB 3 (`nav`) o EPUB 2 (`NCX`), estrae titolo, autore, lingua, copertina e blocchi di testo. `BookImporter` crea libro, capitoli, pagine virtuali e blocchi in una transazione.
- **Pagine virtuali** — i blocchi vengono accumulati fino al limite di caratteri configurato (1.500 per impostazione predefinita). I blocchi più lunghi vengono divisi rispettando gli spazi.
- **Traduzione** — `TranslationService` costruisce un prompt numerato in inglese, con la lingua di destinazione scelta nell'interfaccia come unico target dell'output, richiede al modello un JSON con gli stessi segmenti e valida che il numero di segmenti restituiti coincida con quello inviato. La risposta viene salvata come elenco di `{ block_id, text }`.
- **Batch** — il browser crea un batch tramite API e poi osserva solo lo stato salvato nel database. Il server crea `translation_batches` e `translation_batch_items`, esclude le pagine già in cache e accoda un job per pagina/slot. In serie un account esegue un item alla volta tramite lock e catena i successivi; in parallelo tutti gli item dell'account partono subito. Un batch attivo per libro è garantito da un lock sulla riga del libro; libri diversi procedono in parallelo.
- **Cache** — la chiave è calcolata da pagina, hash del testo sorgente, account, modello, lingua di destinazione, versione del prompt e parametri. Una traduzione completata viene riusata; una fallita può essere ritentata.
- **Allineamento** — il frontend divide blocchi originali e tradotti in frasi con `Intl.Segmenter`, mappa la selezione del browser alle frasi di origine e allinea le frasi tradotte per lunghezza e punteggiatura. Se il numero di frasi coincide, usa l'allineamento 1:1; altrimenti risolve casi 1:2, 2:1 e frasi mancanti.
- **Provider** — `OpenAiCompatibleClient` usa l'endpoint `/chat/completions` e `/models` dell'API OpenAI. Funziona con OpenAI, OpenRouter, Ollama e server compatibili.
- **Frontend** — Inertia collega le pagine React ai controller Laravel. Ant Design è configurato con `StyleProvider layer` e tema personalizzato; Tailwind gestisce il layout. Le pagine sono caricate in modo lazy da Vite.
- **Lingua dell’interfaccia** — `SetLocale` applica il cookie `locale` oppure la lingua dichiarata dal browser, con fallback all’italiano. `i18next` e `react-i18next` traducono il frontend; `lang/{it,en}` contiene messaggi applicativi e validazioni. Ogni batch salva il proprio locale per localizzare anche gli errori generati dai job in background.
- **Code** — la coda usa il driver `database` (tabella `jobs`). Il servizio `queue` avvia `QUEUE_WORKERS` processi `queue:work` sulla coda `translations`. `QUEUE_JOB_TIMEOUT` (default `360`) è il timeout effettivo del job e deve restare sopra `AI_REQUEST_TIMEOUT`; `DB_QUEUE_RETRY_AFTER` deve restare sopra `QUEUE_JOB_TIMEOUT` per evitare doppie esecuzioni.
- **Persistenza** — MariaDB nel container; in test SQLite in memoria. EPUB originali in `storage/app/private/books`; copertine in `storage/app/public/covers`.

## Comandi utili

```bash
make up             # avvia (e costruisce se necessario)
make down           # ferma i container
make logs           # log di Laravel e Vite in tempo reale
make logs-queue     # log dei worker di traduzione
make shell          # shell nel container app
make queue-shell    # shell nel container queue
make artisan ARGS="migrate:status"
make npm ARGS="run build"
make test           # suite Pest
make lint           # Pint (corregge lo stile)
make typecheck      # TypeScript
```

Comandi diretti equivalenti:

```bash
docker compose exec app ./vendor/bin/pest
docker compose exec app ./vendor/bin/pint --test
docker compose exec app npm run typecheck
docker compose exec app npm run build
```

## Test

La suite Pest copre:

- parsing EPUB, metadati, indice, blocchi e copertina;
- importazione, chunking delle pagine e libreria;
- selezione e restituzione delle pagine con traduzioni;
- traduzione, cache, errori del provider, JSON in code fence, segmenti mancanti e lingua di destinazione dinamica;
- creazione batch, un solo batch attivo per libro, batch concorrenti su libri diversi, cache saltata, annullamento e riprova;
- job di batch: successo, errore del provider, annullamento, ripresa dopo crash, account disattivato e catena seriale;
- creazione e aggiornamento account, cifratura della chiave, modalità richieste, sincronizzazione modelli e cancellazione;
- aggiornamento della lingua di destinazione del libro;
- validazione dei capitoli appartenenti al libro;
- rilevamento della lingua da cookie e browser, cambio lingua, cookie annuale e messaggi backend in inglese;
- lingua salvata sul batch e uso della stessa lingua negli errori dei job.

```bash
make test
```

Stato attuale: **63 test, 295 asserzioni**.

## Privacy e sicurezza

- L'applicazione è pensata per l'uso locale: non c'è autenticazione e le porte sono esposte solo su `127.0.0.1`.
- Le chiavi API sono cifrate a riposo con la `APP_KEY` e non compaiono nei payload Inertia né nel DOM.
- I testi dei libri vengono inviati esclusivamente al provider scelto per la traduzione.
- Eliminando un libro vengono rimossi anche capitoli, pagine, blocchi, traduzioni, file EPUB e copertina.
- Non usare questa configurazione così com'è su un server pubblico senza aggiungere autenticazione, HTTPS e una gestione appropriata dei segreti.

## Limiti dell'MVP

- Niente OCR: gli EPUB composti solo da immagini non producono testo traducibile.
- Niente DRM: i file protetti non possono essere importati.
- La formattazione inline (corsivi, grassetti, collegamenti) viene normalizzata in testo semplice; vengono conservati i blocchi strutturali come titoli, paragrafi, citazioni ed elenchi.
- Delle immagini viene estratta solo la copertina.
- I batch sono persistiti e ripresi dai worker, ma non c'è autenticazione: chiunque raggiunga l'app può avviare o interrompere una traduzione.
- Le pagine virtuali sono una suddivisione pratica del testo, non la paginazione dell'edizione originale.
- Il confronto è limitato a due traduzioni contemporanee, come richiesto.
- I modelli piccoli o poco istruiti possono restituire un numero errato di segmenti: l'errore viene mostrato e la richiesta può essere ritentata o assegnata a un altro modello.

## Struttura del progetto

```
app/
  Http/Controllers/       Libreria, pagine, traduzioni, batch, account AI
  Jobs/                   ProcessTranslationBatchItem
  Models/                 Book, Chapter, Page, Block, AiAccount, AiModel, Translation, TranslationBatch, TranslationBatchItem
  Services/Epub/          Parser EPUB e importatore
  Services/Translation/   Servizio di traduzione, client OpenAI e orchestrazione batch
resources/js/
  pages/                  Library, Accounts, Books/Show
  components/             Renderer dei blocchi, pannello batch globale, selettore lingua
  contexts/               Provider del batch globale con polling
  layouts/                Layout condiviso
  i18n/                   Inizializzazione i18next e dizionari it/en
  lib/                    Lingue, messaggi di errore, flash
lang/                     Messaggi applicativi e validazioni it/en
docker/                   PHP ini, entrypoint app e entrypoint queue
tests/                    Suite Pest e fixture EPUB
```

## Risoluzione dei problemi

- **Build fallita con `groupadd: GID '...' already exists`** — l'UID/GID dell'host coincide con un utente/gruppo già presente nell'immagine Debian (su macOS il gid `20` è `staff` sull'host e `dialout` nell'immagine). Il `Dockerfile` attuale riusa gruppo e utente esistenti: aggiornare il progetto e rilanciare `./setup.sh --rebuild`.
- **`SQLSTATE[HY000] [1045] Access denied for user 'book_translation'`** — le credenziali in `.env` non corrispondono a quelle con cui è stato inizializzato il volume `db-data` (volume di un'installazione precedente, `.env` rigenerato oppure `DB_*` esportate nella shell). `./setup.sh` se ne accorge e propone di ricreare il volume; in alternativa eseguire `./setup.sh --reset` (perde il database) oppure allineare `DB_USERNAME`/`DB_PASSWORD`/`DB_ROOT_PASSWORD` in `.env` alle credenziali esistenti. Se si usa `make up` o `docker compose up` direttamente, assicurarsi che `DB_*` non siano esportate nella shell.
- **Pagina bianca o asset non caricati** — controllare che Vite sia attivo sulla porta `VITE_PORT` di `.env` (default `5173`) e che `public/hot` contenga lo stesso URL, ad esempio `http://localhost:5173`. L'entrypoint elimina il file a ogni avvio; se il problema persiste, riavviare con `make down && make up`.
- **Errori di connessione al provider** — usare **Verifica** nella pagina account. Per Ollama controllare `OLLAMA_HOST` e la raggiungibilità della porta dal container.
- **Modello assente dall'elenco** — sincronizzare i modelli oppure digitare l'ID manualmente nel campo della colonna.
- **Timeout con modelli locali grandi** — il primo caricamento può superare i 300 secondi. Aumentare `AI_REQUEST_TIMEOUT` in `.env` e riavviare il container con `make down && make up`.
- **Traduzione fallita per numero di segmenti** — riprovare, ridurre la pagina o scegliere un modello più capace.
- **Batch fermo su “In coda”** — il servizio `queue` non sta elaborando: controllare `docker compose ps`, `make logs-queue` e che `QUEUE_WORKERS` sia almeno `1`. I job restano nel database e ripartono quando il worker torna attivo.
- **Timeout dei job su modelli locali lenti** — aumentare insieme `AI_REQUEST_TIMEOUT`, `QUEUE_JOB_TIMEOUT` e `DB_QUEUE_RETRY_AFTER` (quest'ultimo sempre maggiore del timeout job), poi `make down && make up`.
- **Log** — `make logs` per app/Vite, `make logs-queue` per i worker.

## Licenza

Progetto locale a scopo personale. Laravel, Inertia, React, Ant Design, Tailwind CSS e le rispettive dipendenze mantengono le proprie licenze.
