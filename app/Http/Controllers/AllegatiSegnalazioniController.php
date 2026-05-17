<?php

namespace App\Http\Controllers;

use App\Models\AllegatoSegnalazione;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AllegatiSegnalazioniController extends Controller
{
    private function disk(): string
    {
        return Impostazione::get('allegati_storage_disk', 'local');
    }

    // ── Upload allegati (create o show) ───────────────────────────────────────

    public function store(Request $request, Segnalazione $segnalazione): RedirectResponse
    {
        $this->authorize('view', $segnalazione);

        $maxSizeMb       = (int) Impostazione::get('allegati_max_size_mb', 10);
        $maxPerRequest   = (int) Impostazione::get('allegati_max_per_request', 5);
        $mimeConsentiti  = array_filter(
            array_map('trim', explode(',', Impostazione::get('allegati_mime_consentiti', 'image/jpeg,image/png')))
        );

        $request->validate([
            'allegati'   => ['required', 'array', 'max:' . $maxPerRequest],
            'allegati.*' => [
                'required',
                'file',
                'max:' . ($maxSizeMb * 1024),
                'mimetypes:' . implode(',', $mimeConsentiti),
            ],
        ], [
            'allegati.max'        => "Puoi caricare al massimo {$maxPerRequest} file per volta.",
            'allegati.*.max'      => "Ogni file non può superare {$maxSizeMb} MB.",
            'allegati.*.mimetypes' => 'Tipo di file non consentito.',
        ]);

        $user = auth()->user();

        foreach ($request->file('allegati') as $file) {
            $ext      = $file->getClientOriginalExtension();
            $filename = Str::uuid() . ($ext ? '.' . $ext : '');
            $path     = $file->storeAs(
                'allegati/' . $segnalazione->id_segnalazione,
                $filename,
                $this->disk()
            );

            AllegatoSegnalazione::create([
                'id_segnalazione'     => $segnalazione->id_segnalazione,
                'percorso'            => $path,
                'tipo'                => $file->getMimeType(),
                'nome_originale'      => $file->getClientOriginalName(),
                'dimensione'          => $file->getSize(),
                'id_utente_creazione' => $user->id,
            ]);
        }

        return back()->with('success', 'Allegati caricati con successo.');
    }

    // ── Download sicuro ───────────────────────────────────────────────────────

    public function download(Segnalazione $segnalazione, AllegatoSegnalazione $allegato): StreamedResponse
    {
        $this->authorize('view', $segnalazione);
        abort_if($allegato->id_segnalazione !== $segnalazione->id_segnalazione, 404);
        abort_unless(Storage::disk($this->disk())->exists($allegato->percorso), 404);

        return Storage::disk($this->disk())->download($allegato->percorso, $allegato->nome_originale);
    }

    // ── Elimina allegato ──────────────────────────────────────────────────────

    public function destroy(Segnalazione $segnalazione, AllegatoSegnalazione $allegato): RedirectResponse
    {
        $this->authorize('deleteAllegato', $segnalazione);
        abort_if($allegato->id_segnalazione !== $segnalazione->id_segnalazione, 404);

        Storage::disk($this->disk())->delete($allegato->percorso);
        $allegato->delete();

        return back()->with('success', 'Allegato eliminato.');
    }
}
