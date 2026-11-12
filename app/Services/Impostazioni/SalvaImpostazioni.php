<?php

namespace App\Services\Impostazioni;

use App\Models\Impostazione;
use App\Services\Sla\CalendarioUfficio;
use App\Support\Impostazioni\Sezioni;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Validazione e salvataggio di una sezione di impostazioni, guidati dal tipo di ciascuna chiave. */
class SalvaImpostazioni
{
    /**
     * @param  array<string, mixed>  $input  request()->input('impostazioni', [])
     * @param  array<string, mixed>  $rimuovi  request()->input('impostazioni_rimuovi', []): chiave => '1' per svuotare un segreto
     * @return list<string> chiavi il cui valore salvato è cambiato
     */
    public function salva(string $sezione, array $input, array $rimuovi = []): array
    {
        $mappa = Sezioni::di($sezione);
        $mappa = array_diff_key($mappa, array_flip(Impostazione::CHIAVI_INTERNE));
        $input = array_intersect_key($input, $mappa);

        $this->valida($mappa, $input);

        $cambiate = [];
        foreach ($mappa as $chiave => $tipo) {
            $nuovo = $this->nuovoValore($chiave, $tipo, $input);
            $segreto = $tipo === 'segreto' || $tipo === 'segreto_cifrato';
            if ($segreto && filter_var($rimuovi[$chiave] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                if (filled(Impostazione::get($chiave))) {
                    $cambiate[] = $chiave;
                    Impostazione::set($chiave, null);
                }
                continue;
            }
            if ($nuovo === false) {
                continue; // non toccare
            }

            if (! $this->cambiato($chiave, $tipo, $nuovo)) {
                continue;
            }
            $cambiate[] = $chiave;
            Impostazione::set($chiave, $tipo === 'segreto_cifrato' ? Crypt::encryptString((string) $nuovo) : $nuovo);
        }

        return $cambiate;
    }

    /**
     * Valore da salvare, normalizzato; false = lascia com'è.
     *
     * @param  array<string, mixed>  $input
     */
    private function nuovoValore(string $chiave, string $tipo, array $input): string|false|null
    {
        if ($tipo === 'bool') {
            return filter_var($input[$chiave] ?? false, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }
        if (! array_key_exists($chiave, $input)) {
            return false;
        }
        $valore = $input[$chiave] === null ? '' : trim((string) $input[$chiave]);

        if ($tipo === 'segreto' || $tipo === 'segreto_cifrato') {
            return $valore === '' ? false : $valore;
        }
        if ($tipo === 'orario' || $tipo === 'patrono') {
            return $valore;
        }
        if ($chiave === 'oidc_issuer' && $valore !== '') {
            $valore = rtrim($valore, '/');
        }

        return $valore === '' ? null : $valore;
    }

    /** Confronto sulle forme stringa; per i segreti cifrati sul valore in chiaro. */
    private function cambiato(string $chiave, string $tipo, ?string $nuovo): bool
    {
        $attuale = Impostazione::get($chiave);
        if ($tipo === 'segreto_cifrato') {
            if ($nuovo === null) {
                return false;
            }
            try {
                $attuale = $attuale === null || $attuale === '' ? '' : Crypt::decryptString((string) $attuale);
            } catch (\Throwable) {
                return true;
            }
        }

        return (string) ($attuale ?? '') !== (string) ($nuovo ?? '');
    }

    /**
     * @param  array<string, string>  $mappa
     * @param  array<string, mixed>  $input
     */
    private function valida(array $mappa, array $input): void
    {
        // Un array al posto di un valore (impostazioni[chiave][]=x) non è mai valido.
        $errori = [];
        foreach ($input as $chiave => $valore) {
            if (! is_scalar($valore) && $valore !== null) {
                $errori["impostazioni.$chiave"] = 'Valore non valido.';
            }
        }
        if ($errori) {
            throw ValidationException::withMessages($errori);
        }

        $regole = [];
        foreach ($mappa as $chiave => $tipo) {
            if (! array_key_exists($chiave, $input)) {
                continue;
            }
            $regola = match ($tipo) {
                'testo'    => ['nullable', 'string', 'max:500'],
                'url'      => ['nullable', 'url', 'max:500'],
                'email'    => ['nullable', 'email', 'max:255'],
                'intero'   => ['nullable', 'integer', 'min:0'],
                'colore'   => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
                'ora'      => ['nullable', 'date_format:H:i'],
                'decimale' => ['nullable', 'numeric'],
                'segreto', 'segreto_cifrato' => ['nullable', 'string', 'max:500'],
                'stato'    => ['nullable', 'integer', 'exists:db_stato_segnalazioni,id_stato'],
                'disco'    => ['required', 'in:local,s3'],
                default    => null, // bool, orario, patrono: sotto
            };
            if ($regola !== null) {
                $regole["impostazioni.$chiave"] = $regola;
            }
        }

        $validator = Validator::make(['impostazioni' => $input], $regole, [
            'impostazioni.*.regex' => 'Scegli un colore valido (formato #RRGGBB).',
            'impostazioni.*.url'   => "Scrivi un indirizzo completo, che inizi con http:// o https://.",
            'impostazioni.*.email' => "Scrivi un indirizzo email valido.",
            'impostazioni.*.integer' => 'Scrivi un numero intero.',
            'impostazioni.*.min'   => 'Il valore non può essere negativo.',
            'impostazioni.*.numeric' => 'Scrivi un numero.',
            'impostazioni.*.date_format' => "Scrivi l'ora come HH:MM (es. 07:30).",
            'impostazioni.*.max'   => 'Testo troppo lungo.',
            'impostazioni.*.in'    => 'Scelta non valida.',
            'impostazioni.*.exists' => 'Scelta non valida.',
        ]);
        if ($validator->fails()) {
            $errori = $validator->errors()->toArray();
        }

        $errori = array_merge($errori, $this->erroriOrario($mappa, $input));
        if ($errori) {
            throw ValidationException::withMessages($errori);
        }
    }

    /**
     * Orario d'ufficio: almeno un giorno aperto, fasce e patrono validi.
     *
     * @param  array<string, string>  $mappa
     * @param  array<string, mixed>  $inviate
     * @return array<string, string>
     */
    private function erroriOrario(array $mappa, array $inviate): array
    {
        $errori = [];
        $aperti = 0;
        $toccaOrario = false;
        foreach (CalendarioUfficio::GIORNI as $g) {
            $chiave = "orario_$g";
            if (! isset($mappa[$chiave])) {
                continue;
            }
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
        if (isset($mappa['patrono'])) {
            $patrono = trim((string) ($inviate['patrono'] ?? ''));
            if ($patrono !== '' && ! CalendarioUfficio::validaPatrono($patrono)) {
                $errori['impostazioni.patrono'] = 'Scrivi il giorno del patrono come GG/MM (es. 13/06).';
            }
        }

        return $errori;
    }
}
