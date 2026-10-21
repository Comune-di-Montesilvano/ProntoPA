<?php

namespace App\Services\Backup;

use RuntimeException;

/** BACKUP_PASSPHRASE assente: il backup è disattivato. */
class BackupNonConfigurato extends RuntimeException {}
