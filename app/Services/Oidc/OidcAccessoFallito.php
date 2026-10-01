<?php

namespace App\Services\Oidc;

use RuntimeException;

/** Il proxy ha risposto, ma l'accesso non è valido (token, firma, nonce, audience). */
class OidcAccessoFallito extends RuntimeException {}
