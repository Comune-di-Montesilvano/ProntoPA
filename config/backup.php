<?php

return [
    // Cartella dei backup: volume dedicato, separato da storage/ così un
    // ripristino non può sovrascrivere i backup stessi.
    'path' => env('BACKUP_PATH', base_path('backups')),

    // Passphrase AES-256 dello ZIP. Vuota = backup disattivato.
    'passphrase' => env('BACKUP_PASSPHRASE') ?: null,

    // Radice dei file da archiviare (allegati). Configurabile solo per i test.
    'storage_root' => storage_path('app'),

    // Volatili/derivate; migrations è nel manifest (migrazioniApplicate), nel dump confliggerebbe.
    'tabelle_escluse' => ['migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches'],

    // Colonne cifrate con APP_KEY: nel dump vengono decifrate e ricifrate
    // al ripristino con la APP_KEY del nuovo ambiente.
    'colonne_cifrate' => [
        ['tabella' => 'impostazioni', 'chiave' => 'chiave', 'valore' => 'oidc_client_secret', 'colonna' => 'valore'],
        ['tabella' => 'users', 'colonna' => 'two_factor_secret'],
        ['tabella' => 'users', 'colonna' => 'two_factor_recovery_codes'],
    ],

    // Connessione coda per i job lunghi (backup/ripristino).
    'connessione_coda' => env('BACKUP_QUEUE_CONNECTION', 'redis-lunghi'),

    // Ore senza backup riuscito oltre le quali scatta l'allarme.
    'ore_allarme' => 36,

    // Retention: tutti per N giorni, poi uno a settimana fino a N giorni.
    'giorni_tutti' => 7,
    'giorni_settimanali' => 30,

    // Dimensione massima di un backup caricato da UI (MB).
    'max_upload_mb' => (int) env('BACKUP_MAX_UPLOAD_MB', 10240),

    // Dimensione blocco di lettura/scrittura e di caricamento da UI (byte): sotto
    // il limite di 1 MB dei reverse proxy (nginx client_max_body_size di default).
    'dimensione_blocco' => 512 * 1024,
];
