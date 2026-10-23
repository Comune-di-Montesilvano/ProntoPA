<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Impostazione;
use App\Models\StatoSegnalazione;
use App\Services\Oidc\OidcConfig;
use App\Services\PubblicazioneAutomatica;
use App\Support\Brand;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ImpostazioniController extends Controller
{
    public function index(): View
    {
        $impostazioni = Impostazione::whereNotIn('chiave', Impostazione::CHIAVI_INTERNE)
            ->orderBy('gruppo')->orderBy('chiave')->get()
            ->groupBy('gruppo');

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

            Impostazione::set($chiave, $valore);
        }

        return back()->with('success', 'Impostazioni salvate.');
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
