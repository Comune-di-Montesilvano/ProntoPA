<?php

// Connessione AD/LDAP dei dipendenti: configurazione di avvio (.env), non
// da UI. I nomi dei gruppi → ruolo invece stanno in Admin → Impostazioni
// (gruppo "ldap"), vedi MappaGruppiLdap.
return [
    // "mock" = simulatore dev (mai in produzione); vuoto = AD non configurato.
    'host' => env('LDAP_HOST'),
    'port' => (int) env('LDAP_PORT', 389),
    'base_dn' => env('LDAP_BASE_DN'),
    // %s = username digitato (sAMAccountName), es. "%s@ente.local" (UPN)
    'user_dn_template' => env('LDAP_USER_DN_TEMPLATE', '%s'),
    'starttls' => (bool) env('LDAP_STARTTLS', false),
    'tls_skip_verify' => (bool) env('LDAP_TLS_SKIP_VERIFY', false),
    'timeout' => (int) env('LDAP_TIMEOUT', 5),

    // Gruppi AD → ruolo ProntoPA (precedenza in quest'ordine). In env e non
    // in Impostazioni: servono già al primo login, prima che esista un admin.
    'gruppi' => [
        'admin' => env('LDAP_GRUPPO_ADMIN', 'PRONTOPA_ADMIN'),
        'supervisori' => env('LDAP_GRUPPO_SUPERVISORI', 'PRONTOPA_SUPERVISORI'),
        'gestori' => env('LDAP_GRUPPO_GESTORI', 'PRONTOPA_GESTORI'),
        'operai' => env('LDAP_GRUPPO_OPERAI', 'PRONTOPA_OPERAI'),
        'urp' => env('LDAP_GRUPPO_URP', 'PRONTOPA_URP'),
        'segnalatori' => env('LDAP_GRUPPO_SEGNALATORI', 'PRONTOPA_SEGNALATORI'),
    ],
];
