<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * v0.7.2 — i nomi dei gruppi AD passano in env (LDAP_GRUPPO_*): servono già
 * al primo login, prima che esista un admin che possa cambiarli da UI.
 */
return new class extends Migration
{
    private const CHIAVI = [
        'ldap_gruppo_admin',
        'ldap_gruppo_supervisori',
        'ldap_gruppo_gestori',
        'ldap_gruppo_operai',
        'ldap_gruppo_urp',
        'ldap_gruppo_segnalatori',
    ];

    public function up(): void
    {
        DB::table('impostazioni')->whereIn('chiave', self::CHIAVI)->delete();

        foreach (self::CHIAVI as $chiave) {
            Cache::forget("impostazione:{$chiave}");
        }
    }

    public function down(): void
    {
        // Nessun ripristino: la fonte dei nomi è ora config/ldap.php.
    }
};
