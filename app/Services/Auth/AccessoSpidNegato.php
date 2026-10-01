<?php

namespace App\Services\Auth;

use RuntimeException;

/** Identità SPID valida ma accesso non consentito: il messaggio è per l'utente. */
class AccessoSpidNegato extends RuntimeException {}
