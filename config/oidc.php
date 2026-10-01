<?php

// Login scuole SPID/CIE via pa-sso-proxy. Issuer, client id e secret stanno
// in Admin → Impostazioni (gruppo "spid"); qui solo il simulatore dev.
return [
    // Simulatore SPID per sviluppo (form con codice fiscale). MAI in produzione.
    'mock' => (bool) env('OIDC_MOCK', false),
    'timeout' => (int) env('OIDC_TIMEOUT', 10),
];
