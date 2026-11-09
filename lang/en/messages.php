<?php

return [

    'accounts' => [
        'created' => 'Account “:name” created.',
        'created_with_models' => 'Account “:name” created: :count model imported.|Account “:name” created: :count models imported.',
        'created_without_models' => 'Account “:name” created. The provider returned no models: you can sync them manually.',
        'created_models_error' => 'Account “:name” created, but the models could not be imported: :error You can try again with “Sync models”.',
        'updated' => 'Account updated.',
        'deleted' => 'Account deleted: :name.',
        'models_synced' => ':count model synced.|:count models synced.',
        'connection_ok' => 'Connection successful: :count models available.',
        'inactive' => 'The selected account is not active.',
        'some_inactive' => 'One or more selected accounts are not active.',
    ],

    'books' => [
        'imported' => 'Book imported: :title.',
        'deleted' => 'Book deleted: :title.',
        'invalid_extension' => 'The file must have a .epub extension.',
        'import_failed' => 'Unable to read the EPUB. Make sure it is a valid file without DRM.',
    ],

    'batches' => [
        'already_active' => 'A translation is already running for this book. Wait for it to finish or cancel it.',
        'already_active_other' => 'Another translation is already running for this book. Wait for it to finish or cancel it.',
        'still_running' => 'The translation is still running.',
        'nothing_to_retry' => 'There are no failed translations to retry.',
        'all_cached' => 'All requested translations are already cached.',
        'current_label' => 'Page :page · :model',

        'errors' => [
            'cancelled' => 'Cancelled before execution.',
            'account_missing' => 'The associated AI account no longer exists.',
            'account_disabled' => 'The AI account has been disabled.',
            'interrupted' => 'The translation job was interrupted too many times.',
            'unknown' => 'Unknown error.',
        ],
    ],

    'translation' => [
        'empty_page' => 'The page contains no text to translate.',
        'invalid_segment_count' => 'The model returned :actual segments instead of :expected. Try again or switch models.',
        'invalid_segment_ids' => 'The model returned missing, duplicated, or invalid ids. Try again or switch models.',
        'missing_segment' => 'The model omitted one or more segments.',
        'invalid_json' => 'The model did not return valid JSON.',
        'missing_translations' => 'The model response does not contain the list of translations.',
        'provider_http_error' => 'The provider responded with HTTP :status.',
        'provider_http_error_with_detail' => 'The provider responded with HTTP :status: :detail',
        'provider_empty_response' => 'The provider returned an empty response.',
        'provider_contact_failed' => 'Unable to contact the provider: :error',
        'provider_models_failed' => 'Unable to read the models: HTTP :status :detail',
    ],

    'epub' => [
        'save_failed' => 'Unable to save the EPUB file.',
        'open_failed' => 'Unable to open the EPUB file.',
        'missing_container' => 'The file does not contain META-INF/container.xml.',
        'missing_opf' => 'The EPUB file does not declare an OPF package.',
        'invalid_opf_path' => 'Invalid OPF path.',
        'opf_unreadable' => 'Unable to read the OPF package.',
        'no_text' => 'No readable text found in the EPUB.',
        'untitled_section' => 'Section :number',
    ],

    'export' => [
        'incomplete' => 'The selected chapters cannot be exported because some translations are still missing.',
        'failed' => 'Unable to generate the file. Please try again.',
        'toc' => 'Table of contents',
        'translation_into' => 'Translation into :language',
    ],

];
