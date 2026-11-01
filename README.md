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
- Traduzione in batch con concorrenza 2, avanzamento nel tab, interruzione e riprova degli errori.
- Cache delle traduzioni per pagina, hash del testo, account, modello, lingua e versione del prompt: cambiare colonna è istantaneo quando la traduzione esiste già.
- Allineamento a livello di frase: selezionando una o più frasi nell'originale, le frasi corrispondenti si evidenziano automaticamente in tutte le traduzioni visibili, anche quelle già in cache.
- Chiavi API cifrate nel database e mai serializzate verso il browser.
- Lingua di destinazione predefinita: italiano. Lingua di origine rilevata dall'EPUB.
- Interfaccia interamente in italiano, senza login e pensata per un solo utente locale.

## Requisiti

- Docker con Docker Compose.
- Almeno 2 GB di RAM liberi per il container (i modelli AI girano fuori, sul provider scelto).
- Connessione internet per i provider remoti; nessuna connessione per Ollama locale.

## Avvio rapido

```bash
./setup.sh
```

Lo script fa tutto: crea `.env` da `.env.example` con password del database e `APP_KEY` generate, costruisce l'immagine, avvia app e MariaDB, attende che composer/npm siano installati e che le migrazioni siano applicate, poi verifica che la pagina iniziale risponda. È idempotente: si può rilanciare quando si vuole.

Il primo avvio può richiedere qualche minuto (build dell'immagine + `composer install` + `npm install`).

| Comando | Effetto |
| --- | --- |
| `./setup.sh` | setup completo e avvio (o riavvio) |
| `./setup.sh --skip-build` | riavvia senza ricostruire l'immagine |
| `./setup.sh --rebuild` | ricostruisce l'immagine senza cache |
| `./setup.sh --reset` | elimina container **e volumi** (perde il database) e ricrea tutto |

`make up` resta disponibile come alias rapido di `docker compose up -d --build` (senza le verifiche dello script).

Aprire **<http://localhost:8000>**. Gli avvisi di setup, incluso l'esito dei controlli finali, sono stampati a terminale; in caso di errore lo script mostra le ultime righe dei log e termina con codice diverso da zero.

Le porte sono pubblicate esclusivamente su `127.0.0.1`:

- `8000` — applicazione Laravel;
- `5173` — server di sviluppo Vite (ricarica automatica e HMR).

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

Dopo il salvataggio usare **Sincronizza modelli** per importare l'elenco dei modelli disponibili. I modelli possono anche essere digitati manualmente nello studio, senza sincronizzazione.

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
2. Aprire lo studio del libro e scegliere la lingua di destinazione.
3. Selezionare le pagine da tradurre:
   - clic sulla casella di una pagina;
   - `Shift + clic` per selezionare un intervallo;
   - **Seleziona capitolo** per il capitolo attivo;
   - **Tutto il libro** per l'intero volume.
4. Scegliere account e modello per **Traduzione 1**. Attivare **Confronta due traduzioni** per aggiungere una seconda colonna indipendente.
5. Premere **Traduci selezione** oppure **Traduci** nella singola pagina.
6. Seguire l'avanzamento nel pannello in basso a destra: mostra richieste elaborate, riuscite, errori, richiesta in corso e permette di interrompere o riprovare i fallimenti.
7. Le traduzioni già ottenute vengono mostrate con il tag `cache` e non vengono richieste di nuovo.
8. Selezionare una frase nell'originale: la traduzione corrispondente viene evidenziata automaticamente in ogni colonna visibile.

L'originale resta sempre visibile nella prima colonna. Le colonne di traduzione possono usare lo stesso account con modelli diversi oppure account diversi.

## Come funziona

- **Importazione** — `EpubParser` legge `container.xml`, il package OPF, l'indice EPUB 3 (`nav`) o EPUB 2 (`NCX`), estrae titolo, autore, lingua, copertina e blocchi di testo. `BookImporter` crea libro, capitoli, pagine virtuali e blocchi in una transazione.
- **Pagine virtuali** — i blocchi vengono accumulati fino al limite di caratteri configurato (1.500 per impostazione predefinita). I blocchi più lunghi vengono divisi rispettando gli spazi.
- **Traduzione** — `TranslationService` costruisce un prompt numerato, richiede al modello un JSON con gli stessi segmenti e valida che il numero di segmenti restituiti coincida con quello inviato. La risposta viene salvata come elenco di `{ block_id, text }`.
- **Cache** — la chiave è calcolata da pagina, hash del testo sorgente, account, modello, lingua di destinazione, versione del prompt e parametri. Una traduzione completata viene riusata; una fallita può essere ritentata.
- **Allineamento** — il frontend divide blocchi originali e tradotti in frasi con `Intl.Segmenter`, mappa la selezione del browser alle frasi di origine e allinea le frasi tradotte per lunghezza e punteggiatura. Se il numero di frasi coincide, usa l'allineamento 1:1; altrimenti risolve casi 1:2, 2:1 e frasi mancanti.
- **Provider** — `OpenAiCompatibleClient` usa l'endpoint `/chat/completions` e `/models` dell'API OpenAI. Funziona con OpenAI, OpenRouter, Ollama e server compatibili.
- **Frontend** — Inertia collega le pagine React ai controller Laravel. Ant Design è configurato con `StyleProvider layer` e tema personalizzato; Tailwind gestisce il layout. Le pagine sono caricate in modo lazy da Vite.
- **Persistenza** — SQLite in `database/database.sqlite`; EPUB originali in `storage/app/private/books`; copertine in `storage/app/public/covers`.

## Comandi utili

```bash
make up             # avvia (e costruisce se necessario)
make down           # ferma i container
make logs           # log in tempo reale
make shell          # shell nel container
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
- traduzione, cache, errori del provider, JSON in code fence e segmenti mancanti;
- creazione e aggiornamento account, cifratura della chiave, sincronizzazione modelli e cancellazione;
- validazione dei capitoli appartenenti al libro.

```bash
make test
```

Stato attuale: **18 test, 106 asserzioni**.

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
- Nessuna coda in background: le traduzioni vengono eseguite dal browser e richiedono che il tab resti aperto.
- Le pagine virtuali sono una suddivisione pratica del testo, non la paginazione dell'edizione originale.
- Il confronto è limitato a due traduzioni contemporanee, come richiesto.
- I modelli piccoli o poco istruiti possono restituire un numero errato di segmenti: l'errore viene mostrato e la richiesta può essere ritentata o assegnata a un altro modello.

## Struttura del progetto

```
app/
  Http/Controllers/       Libreria, pagine, traduzioni, account AI
  Models/                 Book, Chapter, Page, Block, AiAccount, AiModel, Translation
  Services/Epub/          Parser EPUB e importatore
  Services/Translation/   Servizio di traduzione e client OpenAI-compatibile
resources/js/
  pages/                  Library, Accounts, Books/Show
  components/             Renderer dei blocchi
  layouts/                Layout condiviso
  lib/                    Lingue, messaggi di errore, flash
docker/                   PHP ini ed entrypoint
tests/                    Suite Pest e fixture EPUB
```

## Risoluzione dei problemi

- **Pagina bianca o asset non caricati** — controllare che Vite sia attivo su `http://localhost:5173` e che `public/hot` contenga `http://localhost:5173`. L'entrypoint elimina il file a ogni avvio; se il problema persiste, riavviare con `make down && make up`.
- **Errori di connessione al provider** — usare **Verifica** nella pagina account. Per Ollama controllare `OLLAMA_HOST` e la raggiungibilità della porta dal container.
- **Modello assente dall'elenco** — sincronizzare i modelli oppure digitare l'ID manualmente nel campo della colonna.
- **Timeout con modelli locali grandi** — il primo caricamento può superare i 300 secondi. Aumentare `AI_REQUEST_TIMEOUT` in `.env` e riavviare il container con `make down && make up`.
- **Traduzione fallita per numero di segmenti** — riprovare, ridurre la pagina o scegliere un modello più capace.
- **Log** — `make logs` oppure `docker compose logs -f app`.

## Licenza

Progetto locale a scopo personale. Laravel, Inertia, React, Ant Design, Tailwind CSS e le rispettive dipendenze mantengono le proprie licenze.
