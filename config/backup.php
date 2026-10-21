<?php

return [
    // Cartella dei backup: volume dedicato, separato da storage/ così un
    // ripristino non può sovrascrivere i backup stessi.
    'path' => env('BACKUP_PATH', base_path('backups')),

    // Passphrase AES-256 dello ZIP. Vuota = backup disattivato.
    'passphrase' => env('BACKUP_PASSPHRASE') ?: null,

    // Volatili/derivate; migrations è nel manifest (migrazioniApplicate), nel dump confliggerebbe.
    'tabelle_escluse' => ['migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches'],

    // Colonne cifrate con APP_KEY: nel dump vengono decifrate e ricifrate
    // al ripristino con la APP_KEY del nuovo ambiente.
    'colonne_cifrate' => [
        ['tabella' => 'impostazioni', 'chiave' => 'chiave', 'valore' => 'oidc_client_secret', 'colonna' => 'valore'],
        ['tabella' => 'users', 'colonna' => 'two_factor_secret'],
        ['tabella' => 'users', 'colonna' => 'two_factor_recovery_codes'],
    ],

    // Ore senza backup riuscito oltre le quali scatta l'allarme.
    'ore_allarme' => 36,

    // Retention: tutti per N giorni, poi uno a settimana fino a N giorni.
    'giorni_tutti' => 7,
    'giorni_settimanali' => 30,

    // Dimensione blocco di lettura/scrittura (byte).
    'dimensione_blocco' => 5 * 1024 * 1024,
];
