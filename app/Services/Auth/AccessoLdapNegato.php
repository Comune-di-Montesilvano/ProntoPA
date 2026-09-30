<?php

namespace App\Services\Auth;

use RuntimeException;

/** Credenziali AD valide ma accesso non consentito: il messaggio è per l'utente. */
class AccessoLdapNegato extends RuntimeException {}
