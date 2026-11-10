<?php

return [

    'accounts' => [
        'created' => 'Account “:name” creato.',
        'created_with_models' => 'Account “:name” creato: :count modello importato.|Account “:name” creato: :count modelli importati.',
        'created_without_models' => 'Account “:name” creato. Il provider non ha restituito modelli: potrai sincronizzarli manualmente.',
        'created_models_error' => 'Account “:name” creato, ma non è stato possibile importare i modelli: :error Potrai riprovare con “Sincronizza modelli”.',
        'updated' => 'Account aggiornato.',
        'deleted' => 'Account eliminato: :name.',
        'models_synced' => ':count modello sincronizzato.|:count modelli sincronizzati.',
        'connection_ok' => 'Connessione riuscita: :count modelli disponibili.',
        'inactive' => 'L’account selezionato non è attivo.',
        'some_inactive' => 'Uno o più account selezionati non sono attivi.',
    ],

    'books' => [
        'imported' => 'Libro importato: :title.',
        'deleted' => 'Libro eliminato: :title.',
        'invalid_extension' => 'Il file deve avere estensione .epub.',
        'import_failed' => 'Impossibile leggere l’EPUB. Verifica che sia un file valido e senza DRM.',
    ],

    'batches' => [
        'already_active' => 'C’è già una traduzione in corso per questo libro. Attendi o interrompila.',
        'already_active_other' => 'C’è già un’altra traduzione in corso per questo libro. Attendi o interrompila.',
        'still_running' => 'La traduzione è ancora in corso.',
        'nothing_to_retry' => 'Non ci sono traduzioni fallite da riprovare.',
        'all_cached' => 'Tutte le traduzioni richieste sono già in cache.',
        'current_label' => 'Pagina :page · :model',

        'errors' => [
            'cancelled' => 'Annullata prima dell’esecuzione.',
            'account_missing' => 'L’account AI associato non esiste più.',
            'account_disabled' => 'L’account AI è stato disattivato.',
            'interrupted' => 'Il job di traduzione è stato interrotto più volte.',
            'unknown' => 'Errore sconosciuto.',
        ],
    ],

    'translation' => [
        'empty_page' => 'La pagina non contiene testo da tradurre.',
        'invalid_segment_count' => 'Il modello ha restituito :actual segmenti invece di :expected. Riprova o cambia modello.',
        'invalid_segment_ids' => 'Il modello ha restituito id mancanti, duplicati o non validi. Riprova o cambia modello.',
        'missing_segment' => 'Il modello ha omesso uno o più segmenti.',
        'invalid_json' => 'Il modello non ha restituito un JSON valido.',
        'missing_translations' => 'La risposta del modello non contiene l’elenco delle traduzioni.',
        'provider_http_error' => 'Il provider ha risposto con HTTP :status.',
        'provider_http_error_with_detail' => 'Il provider ha risposto con HTTP :status: :detail',
        'provider_empty_response' => 'Il provider ha restituito una risposta vuota.',
        'provider_contact_failed' => 'Impossibile contattare il provider: :error',
        'provider_models_failed' => 'Impossibile leggere i modelli: HTTP :status :detail',
    ],

    'epub' => [
        'save_failed' => 'Impossibile salvare il file EPUB.',
        'open_failed' => 'Impossibile aprire il file EPUB.',
        'missing_container' => 'Il file non contiene META-INF/container.xml.',
        'missing_opf' => 'Il file EPUB non dichiara un package OPF.',
        'invalid_opf_path' => 'Percorso OPF non valido.',
        'opf_unreadable' => 'Impossibile leggere il package OPF.',
        'no_text' => 'Nessun testo leggibile trovato nell’EPUB.',
        'untitled_section' => 'Sezione :number',
    ],

    'export' => [
        'incomplete' => 'Non è possibile esportare: mancano ancora delle traduzioni nei capitoli selezionati.',
        'failed' => 'Impossibile generare il file. Riprova.',
        'toc' => 'Indice',
        'translation_into' => 'Traduzione in :language',
    ],

];
