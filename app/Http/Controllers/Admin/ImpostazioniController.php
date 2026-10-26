<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RicalcolaScadenzeSla;
use App\Models\Impostazione;
use App\Models\StatoSegnalazione;
use App\Services\Oidc\OidcConfig;
use App\Services\PubblicazioneAutomatica;
use App\Services\Sla\CalendarioUfficio;
use App\Support\Brand;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ImpostazioniController extends Controller
{
    public function index(): View
    {
        $impostazioni = Impostazione::whereNotIn('chiave', Impostazione::CHIAVI_INTERNE)
            ->orderBy('gruppo')->orderBy('chiave')->get()
            ->groupBy('gruppo');
        if ($impostazioni->has('orario')) {
            $impostazioni['orario'] = $this->ordinaOrario($impostazioni['orario']);
        }

        $statiSegnalazioni = StatoSegnalazione::orderBy('id_stato')->get();

        $oidcRedirectUri = app(OidcConfig::class)->redirectUri();

        return view('admin.impostazioni', compact('impostazioni', 'statiSegnalazioni', 'oidcRedirectUri'));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'impostazioni'          => ['required', 'array'],
            'impostazioni.*'        => ['nullable', 'string', 'max:500'],
            // Niente SVG: servito dal nostro dominio potrebbe eseguire script.
            'ente_logo_upload'      => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            'rimuovi_logo_file'     => ['nullable', 'boolean'],
        ]);

        $this->validaOrario($data['impostazioni']);
        $orarioPrima = $this->orarioAttuale();

        if ($request->boolean('rimuovi_logo_file') || $request->hasFile('ente_logo_upload')) {
            $this->cancellaLogoFile();
        }

        if ($request->hasFile('ente_logo_upload')) {
            $file = $request->file('ente_logo_upload');
            $ext = $file->extension() === 'jpeg' ? 'jpg' : $file->extension();
            $percorso = $file->storeAs(Brand::DIR_LOGO, "logo-ente.{$ext}", 'local');
            Impostazione::set('ente_logo_file', $percorso);
        }

        foreach ($data['impostazioni'] as $chiave => $valore) {
            if (in_array($chiave, Impostazione::CHIAVI_INTERNE, true)) {
                continue;
            }

            // Cast specifici per certi campi
            if ($chiave === 'publication_auto_state_id' && $valore !== '' && $valore !== null) {
                $valore = (int) $valore;
            }

            if ($chiave === 'oidc_client_secret') {
                if (blank($valore)) {
                    continue;
                }
                $valore = Crypt::encryptString((string) $valore);
            }

            if ($chiave === 'oidc_issuer' && filled($valore)) {
                $valore = rtrim(trim((string) $valore), '/');
            }

            if ($this->isChiaveOrario($chiave)) {
                $valore = trim((string) $valore);
            }

            Impostazione::set($chiave, $valore);
        }

        if ($this->orarioAttuale() !== $orarioPrima) {
            RicalcolaScadenzeSla::dispatch();

            return back()->with('success', 'Impostazioni salvate: scadenze delle segnalazioni aperte in aggiornamento.');
        }

        return back()->with('success', 'Impostazioni salvate.');
    }

    private function isChiaveOrario(string $chiave): bool
    {
        return $chiave === 'patrono' || str_starts_with($chiave, 'orario_');
    }

    /** @return array<string, string> orario e patrono come salvati ora */
    private function orarioAttuale(): array
    {
        $valori = [];
        foreach ([...array_map(fn ($g) => "orario_$g", CalendarioUfficio::GIORNI), 'patrono'] as $chiave) {
            $valori[$chiave] = trim((string) Impostazione::get($chiave, ''));
        }

        return $valori;
    }

    /** @param  array<string, mixed>  $inviate */
    private function validaOrario(array $inviate): void
    {
        $errori = [];
        $aperti = 0;
        $toccaOrario = false;
        foreach (CalendarioUfficio::GIORNI as $g) {
            $chiave = "orario_$g";
            $toccaOrario = $toccaOrario || array_key_exists($chiave, $inviate);
            $fascia = trim((string) (array_key_exists($chiave, $inviate) ? $inviate[$chiave] : Impostazione::get($chiave, '')));
            if ($fascia === '') {
                continue;
            }
            if (! CalendarioUfficio::valida($fascia)) {
                $errori["impostazioni.$chiave"] = "Scrivi l'orario come 08:00-14:00, con l'apertura prima della chiusura (vuoto = chiuso).";
            }
            $aperti++;
        }
        if ($toccaOrario && $aperti === 0) {
            $errori['impostazioni.orario_lun'] = 'Almeno un giorno deve essere aperto: senza orario le scadenze non si possono calcolare.';
        }
        $patrono = trim((string) ($inviate['patrono'] ?? ''));
        if ($patrono !== '' && ! CalendarioUfficio::validaPatrono($patrono)) {
            $errori['impostazioni.patrono'] = 'Scrivi il giorno del patrono come GG/MM (es. 13/06).';
        }
        if ($errori) {
            throw ValidationException::withMessages($errori);
        }
    }

    /**
     * Giorni nell'ordine della settimana, patrono in fondo.
     *
     * @param  Collection<int, Impostazione>  $voci
     * @return Collection<int, Impostazione>
     */
    private function ordinaOrario(Collection $voci): Collection
    {
        $ordine = [...array_map(fn ($g) => "orario_$g", CalendarioUfficio::GIORNI), 'patrono'];

        return $voci->sortBy(fn ($imp) => array_search($imp->chiave, $ordine, true))->values();
    }

    private function cancellaLogoFile(): void
    {
        $attuale = Impostazione::get('ente_logo_file');
        if (filled($attuale)) {
            Storage::disk('local')->delete($attuale);
        }
        Impostazione::set('ente_logo_file', null);
    }

    public function pubblicaEsistenti(PubblicazioneAutomatica $pubblicazione): RedirectResponse
    {
        $count = $pubblicazione->applicaAEsistenti();

        if ($count === null) {
            return back()->with('error', 'Pubblicazione automatica non attiva: impostala su "Sì", scegli lo stato e salva.');
        }

        return back()->with('success', "Regola applicata: {$count} segnalazioni pubblicate.");
    }
}
