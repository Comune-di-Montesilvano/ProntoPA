<?php

namespace App\Services\Segnalazioni;

use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\FineLavoriNotification;
use App\Services\SegnalazioneWorkflowService;
use App\Support\Segnalazioni\CaricaAllegati;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Ditta o operaio dicono "ho finito": rapportino come nota, foto facoltative, avviso al comune.
 * La segnalazione si chiude subito, salvo lavoro di ditta con preventivo: lì verifica il comune.
 */
class FineLavoriService
{
    private const CHIUDI = 9;

    public function __construct(private readonly SegnalazioneWorkflowService $workflow) {}

    /**
     * @param  array{ore_lavoro?: mixed, materiali?: ?string, nota: string}  $dati
     * @param  iterable<UploadedFile>  $foto
     * @return bool true se la segnalazione è stata chiusa
     */
    public function comunica(Segnalazione $segnalazione, User $autore, array $dati, iterable $foto): bool
    {
        $righe = ['Fine lavori comunicata:'];
        if (filled($dati['ore_lavoro'] ?? null)) {
            $righe[] = '- Ore impiegate: '.$dati['ore_lavoro'];
        }
        if (filled($dati['materiali'] ?? null)) {
            $righe[] = '- Materiali usati: '.$dati['materiali'];
        }
        $testo = implode("\n", $righe)."\n\nDescrizione:\n".$dati['nota'];

        $nota = DB::transaction(function () use ($segnalazione, $autore, $testo, $foto) {
            CaricaAllegati::salva($segnalazione, $foto, $autore, 'dopo');
            $segnalazione->forceFill(['fine_lavori_at' => now()])->save();

            return $segnalazione->note()->create([
                'testo' => $testo,
                'id_utente' => $autore->id,
                'visibile_web' => false,
                'visibile_impresa' => true,
            ]);
        });

        $comune = DestinatariComune::per($segnalazione, $autore);
        if ($comune->isNotEmpty()) {
            Notification::send($comune, new FineLavoriNotification($segnalazione, $nota, $this->mittente($autore)));
        }

        if ($autore->hasRole('impresa') && $segnalazione->haPreventivo()) {
            return false;
        }

        $this->workflow->eseguiAzione($segnalazione->refresh(), self::CHIUDI, $autore);

        return true;
    }

    private function mittente(User $autore): string
    {
        return $autore->hasRole('impresa')
            ? (string) ($autore->impresa->ragione_sociale ?? $autore->name)
            : (string) $autore->name;
    }
}
