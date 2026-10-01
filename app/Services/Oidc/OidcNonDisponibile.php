<?php

namespace App\Services\Oidc;

use RuntimeException;

/** Proxy irraggiungibile/5xx, discovery o JWKS non leggibili, OIDC non configurato. */
class OidcNonDisponibile extends RuntimeException {}
