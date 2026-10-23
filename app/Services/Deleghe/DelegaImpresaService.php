<?php

namespace App\Services\Deleghe;

use App\Models\DelegaImpresa;
use App\Models\Impresa;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deleghe ditta: sempre pre-delega di un operatore (codice fiscale →
 * impresa). Il ruolo `impresa` e `id_impresa` degli utenti SPID li decide
 * solo allinea(), chiamato a ogni login SPID e a ogni cambio di delega.
 */
class DelegaImpresaService
{
    public function predelega(string $codiceFiscale, Impresa $impresa, ?string $motivo, User $operatore): DelegaImpresa
    {
        $cf = self::normalizzaCf($codiceFiscale);

        if (! preg_match('/^[A-Z0-9]{16}$/', $cf)) {
            throw ValidationException::withMessages(['codice_fiscale' => 'Codice fiscale di 16 caratteri.']);
        }

        // null = appena creata senza refresh: il default DB è attiva.
        if ($impresa->attiva === false) {
            throw ValidationException::withMessages(['codice_fiscale' => 'La ditta è disattivata: riattivala prima di delegare.']);
        }

        $delega = DB::transaction(function () use ($cf, $impresa, $motivo, $operatore) {
            // Una persona = una ditta.
            $esistente = DelegaImpresa::attive()->where('codice_fiscale', $cf)->with('impresa')->lockForUpdate()->first();
            if ($esistente !== null) {
                throw ValidationException::withMessages([
                    'codice_fiscale' => 'Questa persona gestisce già '.($esistente->impresa->ragione_sociale ?? "un'altra ditta").'.',
                ]);
            }

            return DelegaImpresa::create([
                'codice_fiscale' => $cf,
                'id_impresa' => $impresa->id_impresa,
                'stato' => DelegaImpresa::ATTIVA,
                'motivo' => $motivo,
                'creata_da' => $operatore->id,
            ]);
        });

        $user = User::where('codice_fiscale', $cf)->where('auth_source', 'spid')->first();
        if ($user !== null) {
            $this->allinea($user);
        }

        return $delega->fresh();
    }

    public function revoca(DelegaImpresa $delega, User $operatore, ?string $motivo = null): void
    {
        $delega->update([
            'stato' => DelegaImpresa::REVOCATA,
            'revocata_da' => $operatore->id,
            'revocata_at' => now(),
            'motivo' => $motivo ?? $delega->motivo,
        ]);

        if ($delega->user !== null) {
            $this->allinea($delega->user);
        }
    }

    public function disattivaImpresa(Impresa $impresa, User $operatore): void
    {
        $impresa->update(['attiva' => false]);

        DelegaImpresa::attive()->where('id_impresa', $impresa->id_impresa)->get()
            ->each(fn (DelegaImpresa $d) => $this->revoca($d, $operatore, 'Ditta disattivata'));
    }

    /** Le deleghe revocate restano revocate: l'operatore le rifà. */
    public function riattivaImpresa(Impresa $impresa): void
    {
        $impresa->update(['attiva' => true]);
    }

    /**
     * Idempotente. Solo utenti SPID: aggancia le pre-deleghe del suo codice
     * fiscale e allinea ruolo/impresa alla delega attiva (se c'è).
     */
    public function allinea(User $user): void
    {
        if (! $user->isSpid() || blank($user->codice_fiscale)) {
            return;
        }

        DelegaImpresa::attive()->whereNull('user_id')->where('codice_fiscale', $user->codice_fiscale)
            ->update(['user_id' => $user->id]);

        $attiva = DelegaImpresa::attive()->where('user_id', $user->id)->first();

        if ($attiva !== null) {
            if (! $user->hasRole('impresa') || $user->id_impresa !== $attiva->id_impresa) {
                $user->forceFill(['id_impresa' => $attiva->id_impresa])->save();
                $user->syncRoles(['impresa']);
            }

            return;
        }

        if ($user->hasRole('impresa') || $user->id_impresa !== null) {
            $user->forceFill(['id_impresa' => null])->save();
            $user->syncRoles(['segnalatore']);
        }
    }

    public static function normalizzaCf(string $codiceFiscale): string
    {
        return (string) preg_replace('/^TINIT-/', '', strtoupper(trim($codiceFiscale)));
    }
}
