<?php

namespace App\Services\Backup;

use RuntimeException;

/** Tentata eliminazione dell'ultimo pre-ripristino, unica via di ritorno dopo un ripristino. */
class BackupProtetto extends RuntimeException {}
