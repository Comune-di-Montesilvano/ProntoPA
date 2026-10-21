<?php

namespace App\Services\Backup;

use RuntimeException;

/** Archivio di backup illeggibile o alterato: il messaggio è mostrabile all'admin. */
class BackupNonLeggibile extends RuntimeException {}
