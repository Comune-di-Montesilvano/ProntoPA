<?php

namespace App\Support\Segnalazioni;

use App\Models\AllegatoSegnalazione;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/** Regole e salvataggio degli allegati di una segnalazione, uguali per ogni punto di caricamento. */
class CaricaAllegati
{
    public static function disco(): string
    {
        return Impostazione::get('allegati_storage_disk', 'local');
    }

    /** @return array<string, list<string>> */
    public static function regole(bool $obbligatori = false): array
    {
        $maxSizeMb = (int) Impostazione::get('allegati_max_size_mb', 10);
        $maxPerRequest = (int) Impostazione::get('allegati_max_per_request', 5);
        $mime = array_filter(array_map('trim', explode(',', Impostazione::get('allegati_mime_consentiti', 'image/jpeg,image/png'))));

        return [
            'allegati' => $obbligatori
                ? ['required', 'array', 'min:1', 'max:'.$maxPerRequest]
                : ['nullable', 'array', 'max:'.$maxPerRequest],
            'allegati.*' => ['required', 'file', 'max:'.($maxSizeMb * 1024), 'mimetypes:'.implode(',', $mime)],
        ];
    }

    /** @return array<string, string> */
    public static function messaggi(): array
    {
        $maxSizeMb = (int) Impostazione::get('allegati_max_size_mb', 10);
        $maxPerRequest = (int) Impostazione::get('allegati_max_per_request', 5);

        return [
            'allegati.max' => "Puoi caricare al massimo {$maxPerRequest} file per volta.",
            'allegati.*.max' => "Ogni file non può superare {$maxSizeMb} MB.",
            'allegati.*.mimetypes' => 'Tipo di file non consentito.',
        ];
    }

    /**
     * Senza fase l'allegato prende il default di colonna ("prima").
     *
     * @param  iterable<UploadedFile>  $files
     */
    public static function salva(Segnalazione $segnalazione, iterable $files, User $utente, ?string $fase = null): void
    {
        foreach ($files as $file) {
            $ext = $file->getClientOriginalExtension();
            $path = $file->storeAs(
                'allegati/'.$segnalazione->id_segnalazione,
                Str::uuid().($ext ? '.'.$ext : ''),
                self::disco()
            );

            AllegatoSegnalazione::create(array_filter([
                'id_segnalazione' => $segnalazione->id_segnalazione,
                'percorso' => $path,
                'tipo' => $file->getMimeType(),
                'nome_originale' => $file->getClientOriginalName(),
                'dimensione' => $file->getSize(),
                'id_utente_creazione' => $utente->id,
                'fase' => $fase,
            ], fn ($v) => $v !== null));
        }
    }
}
