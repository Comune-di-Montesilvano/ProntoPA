<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AllineamentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * L'entrypoint lancia AllineamentoSeeder a ogni avvio: su un'installazione
 * nata con una versione precedente deve aggiungere ruoli, permessi e dati di
 * riferimento introdotti dopo, senza toccare quelli già presenti.
 */
class AllineamentoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_aggiunge_permesso_mancante_su_installazione_esistente(): void
    {
        Role::findOrCreate('gestore', 'web');
        User::factory()->create()->assignRole('gestore');

        $this->seed(AllineamentoSeeder::class);

        $this->assertTrue(Permission::where('name', 'segnalazioni.per-conto')->exists());
        $this->assertTrue(Role::findByName('admin')->hasPermissionTo('segnalazioni.per-conto'));
        $this->assertTrue(Role::findByName('gestore')->hasPermissionTo('segnalazioni.per-conto'));
        foreach (['admin', 'gestore', 'operaio', 'segnalatore', 'impresa'] as $ruolo) {
            $this->assertTrue(Role::where('name', $ruolo)->exists(), $ruolo);
        }
    }

    public function test_rilanciato_a_ogni_avvio_non_duplica_nulla(): void
    {
        $this->seed(AllineamentoSeeder::class);
        $conteggi = fn () => [
            Role::count(),
            Permission::count(),
            DB::table('impostazioni')->count(),
            DB::table('tipologie_segnalazioni')->count(),
            DB::table('db_stato_segnalazioni')->count(),
        ];
        $prima = $conteggi();

        $this->seed(AllineamentoSeeder::class);

        $this->assertSame($prima, $conteggi());
    }

    public function test_non_tocca_i_ruoli_degli_utenti_esistenti(): void
    {
        $this->seed(AllineamentoSeeder::class);
        // Flag legacy incoerenti col ruolo: il ruolo vero è quello Spatie
        // (per gli utenti AD lo ricalcola il login), l'avvio non lo riscrive.
        $operaio = User::factory()->create(['amministratore' => true]);
        $operaio->syncRoles(['operaio']);
        $senzaRuolo = User::factory()->create(['amministratore' => false, 'gestore_segnalazioni' => false]);

        $this->seed(AllineamentoSeeder::class);

        $this->assertSame(['operaio'], $operaio->fresh()->getRoleNames()->all());
        $this->assertSame([], $senzaRuolo->fresh()->getRoleNames()->all());
    }

    public function test_non_sovrascrive_impostazioni_configurate(): void
    {
        $this->seed(AllineamentoSeeder::class);
        DB::table('impostazioni')->where('chiave', 'ente_nome')->update(['valore' => 'Comune di Prova']);

        $this->seed(AllineamentoSeeder::class);

        $this->assertSame('Comune di Prova', DB::table('impostazioni')->where('chiave', 'ente_nome')->value('valore'));
    }
}
