<?php

namespace App\Http\Controllers;

use App\Models\AllegatoSegnalazione;
use App\Models\Azione;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Services\SegnalazioneWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class MagicLinkController extends Controller
{
    public function __construct(
        private readonly SegnalazioneWorkflowService $workflow,
    ) {}

    public function show(Request $request, Segnalazione $segnalazione): View
    {
        $segnalazione->load([
            'stato', 'tipologia.gruppo', 'provenienza', 'plesso.istituto',
            'operatore', 'utente', 'appalto.impresa', 'specializzazione',
            'squadraAssegnata',
            'note.autore',
            'storicoStati.stato', 'storicoStati.utente',
            'allegati.utenteCreazione',
        ]);

        // Trova un utente con ruolo impresa per simulare le azioni consentite all'impresa
        $user = User::role('impresa')
            ->where('id_impresa', $segnalazione->appalto?->id_impresa)
            ->first() ?? User::role('impresa')->first();

        if ($user) {
            $azioniDisponibili = $this->workflow->getAzioniDisponibili($segnalazione, $user);
        } else {
            $azioniDisponibili = collect();
        }

        $note = $segnalazione->note()
            ->where('visibile_impresa', true)
            ->with('autore')
            ->orderByDesc('data')
            ->get();

        $fotoPrima = $segnalazione->allegati->where('fase', 'prima');
        $fotoDopo = $segnalazione->allegati->where('fase', 'dopo');

        return view('imprese.magic-show', compact('segnalazione', 'azioniDisponibili', 'note', 'fotoPrima', 'fotoDopo'));
    }

    public function eseguiAzione(Request $request, Segnalazione $segnalazione): RedirectResponse
    {
        $azioneId = (int) $request->input('id_azione');
        $azione = Azione::findOrFail($azioneId);

        // Trova l'utente per eseguire l'azione
        $user = User::role('impresa')
            ->where('id_impresa', $segnalazione->appalto?->id_impresa)
            ->first() ?? User::role('impresa')->first();

        if (! $user) {
            abort(403, 'Nessun utente impresa configurato per eseguire l\'azione.');
        }

        $rules = [
            'id_azione'  => ['required', 'integer', 'exists:db_azioni,id_azione'],
            'nota'       => ['nullable', 'string', 'max:2000'],
        ];

        if ($azione->flag_preventivo) {
            $rules['importo_preventivo'] = ['required', 'numeric', 'min:0'];
        }

        if ($azioneId === 6) {
            $rules['ore_lavoro'] = ['required', 'numeric', 'min:0'];
            $rules['materiali']  = ['required', 'string', 'max:1000'];
            $rules['nota']       = ['required', 'string', 'max:2000']; // Descrizione obbligatoria

            $maxSizeMb      = (int) Impostazione::get('allegati_max_size_mb', 10);
            $maxPerRequest  = (int) Impostazione::get('allegati_max_per_request', 5);
            $mimeConsentiti = array_filter(
                array_map('trim', explode(',', Impostazione::get('allegati_mime_consentiti', 'image/jpeg,image/png')))
            );

            $rules['allegati']   = ['required', 'array', 'min:1', 'max:' . $maxPerRequest];
            $rules['allegati.*'] = [
                'file',
                'max:' . ($maxSizeMb * 1024),
                'mimetypes:' . implode(',', $mimeConsentiti),
            ];
        }

        $data = $request->validate($rules, [
            'allegati.required' => 'Devi caricare almeno una foto dell\'intervento per proporre la chiusura.',
            'allegati.min' => 'Devi caricare almeno una foto dell\'intervento per proporre la chiusura.',
        ]);

        $this->workflow->eseguiAzione(
            $segnalazione,
            $azioneId,
            $user,
            $data
        );

        if ($request->hasFile('allegati') && $azioneId === 6) {
            $disk = Impostazione::get('allegati_storage_disk', 'local');

            foreach ($request->file('allegati') as $file) {
                $ext      = $file->getClientOriginalExtension();
                $filename = Str::uuid() . ($ext ? '.' . $ext : '');
                $path     = $file->storeAs(
                    'allegati/' . $segnalazione->id_segnalazione,
                    $filename,
                    $disk
                );
                AllegatoSegnalazione::create([
                    'id_segnalazione'     => $segnalazione->id_segnalazione,
                    'percorso'            => $path,
                    'tipo'                => $file->getMimeType(),
                    'nome_originale'      => $file->getClientOriginalName(),
                    'dimensione'          => $file->getSize(),
                    'id_utente_creazione' => $user->id,
                    'fase'                => 'dopo',
                ]);
            }
        }

        $redirectUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'magic-link.show',
            now()->addDays(30),
            ['segnalazione' => $segnalazione->id_segnalazione]
        );
        return redirect()->to($redirectUrl)->with('success', 'Azione eseguita con successo via link sicuro.');
    }
}
