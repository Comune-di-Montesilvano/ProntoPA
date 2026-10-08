<?php

namespace Tests\Support;

use App\Models\Delega;
use App\Models\Istituto;
use App\Models\Plesso;
use App\Models\User;
use Illuminate\Support\Str;

/** Richiede RolesAndPermissionsSeeder (ruolo segnalatore). */
trait DelegheFixture
{
    protected function istitutoConPlessi(string $codice = 'PEIC828004', ?string $email = 'peic828004@istruzione.it', int $plessi = 2): Istituto
    {
        $istituto = Istituto::create([
            'descrizione' => "IC {$codice}", 'codice_meccanografico' => $codice, 'email' => $email,
            'tipo' => 'Scuola', 'tipo_ente' => 'scuola', 'attivo' => true,
        ]);

        for ($i = 1; $i <= $plessi; $i++) {
            Plesso::create(['id_istituto' => $istituto->id_istituto, 'nome' => "Plesso {$i} {$codice}", 'codice_meccanografico' => substr($codice, 0, 8).$i]);
        }

        return $istituto->fresh();
    }

    protected function utenteSpid(string $cf = 'RSSMRA80A01G482X', bool $verificato = true): User
    {
        $user = User::factory()->create([
            'auth_source' => 'spid', 'codice_fiscale' => $cf, 'username' => $cf, 'password' => null,
            'email_verified_at' => $verificato ? now() : null, 'attivo' => true, 'approval_status' => 'approved',
        ]);
        $user->assignRole('segnalatore');

        return $user;
    }

    /** @return list<int> */
    protected function idPlessi(Istituto $istituto): array
    {
        return $istituto->plessi()->orderBy('id_plesso')->pluck('id_plesso')->map(fn ($id) => (int) $id)->all();
    }

    /** @param array<string, mixed> $extra */
    protected function delega(User $user, Istituto $istituto, ?int $idPlesso, string $stato = Delega::ATTIVA, array $extra = []): Delega
    {
        return Delega::create(array_merge([
            'user_id' => $user->id,
            'codice_fiscale' => $user->codice_fiscale,
            'id_istituto' => $istituto->id_istituto,
            'id_plesso' => $idPlesso,
            'gruppo_richiesta' => (string) Str::uuid(),
            'stato' => $stato,
            'email_destinatario' => $istituto->email,
            'valida_fino_at' => $stato === Delega::ATTIVA ? now()->addYear() : null,
        ], $extra));
    }
}
