<?php

namespace App\Services\Backup;

use RuntimeException;

/** Un altro backup o ripristino è già in esecuzione: il lock 'backup' è occupato. */
class BackupInCorso extends RuntimeException {}
