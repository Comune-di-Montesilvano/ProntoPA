<?php

namespace App\Support\Segnalazioni;

use App\Models\Impostazione;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\MimeTypes;

/**
 * Foto caricate a blocchi prima dell'invio del form (1.0.20): il reverse proxy dell'ente
 * accetta richieste fino a 1 MB, quindi ogni foto arriva da sola, in blocchi da 512 KB.
 * Il form invia solo gli id (`allegati_caricati[]`): il middleware RicomponiAllegatiCaricati
 * li rimette in `allegati[]` come file normali, così validazione e salvataggio non cambiano.
 *
 * Una cartella per utente sul disco `local`: `{id}.part` (i byte) e `{id}.json` (totale, nome, completo).
 */
class AllegatiTemporanei
{
    public const DIMENSIONE_BLOCCO = 524288;

    /** Oltre, la foto non è stata usata: si cancella. */
    public const ORE_VITA = 24;

    /** Foto in attesa per utente: oltre, i blocchi nuovi sono rifiutati. */
    public const MAX_PER_UTENTE = 30;

    private const CARTELLA = 'allegati-temporanei';

    /**
     * Aggiunge un blocco. I blocchi arrivano in ordine: tutti di DIMENSIONE_BLOCCO byte tranne l'ultimo.
     *
     * @return array{completo: bool}
     *
     * @throws \InvalidArgumentException con il messaggio da mostrare
     */
    public static function ricevi(User $utente, string $id, int $indice, int $totale, string $nome, UploadedFile $blocco): array
    {
        if (! Str::isUuid($id) || $indice < 0 || $totale < 1 || $indice >= $totale) {
            throw new \InvalidArgumentException('Blocco non valido: riprova.');
        }

        $maxByte = self::maxByte();
        [$parte, $meta] = self::percorsi($utente, $id);

        if ($indice === 0) {
            self::pulisci($utente);
            if (($totale - 1) * self::DIMENSIONE_BLOCCO >= $maxByte) {
                throw new \InvalidArgumentException(self::messaggioTroppoGrande());
            }
            if (count(glob(self::cartella($utente).'/*.json') ?: []) >= self::MAX_PER_UTENTE) {
                throw new \InvalidArgumentException('Troppe foto in attesa di invio: invia o ricarica la pagina.');
            }
            File::ensureDirectoryExists(self::cartella($utente), 0700);
            @unlink($parte);
            self::scriviMeta($meta, ['totale' => $totale, 'nome' => self::nomePulito($nome), 'completo' => false]);
        }

        $dati = is_file($meta) ? json_decode((string) file_get_contents($meta), true) : null;
        if (! is_array($dati) || ($dati['totale'] ?? null) !== $totale || ($dati['completo'] ?? false)) {
            throw new \InvalidArgumentException('Caricamento non riconosciuto: togli la foto e riprova.');
        }

        $ultimo = $indice === $totale - 1;
        $dimensione = (int) $blocco->getSize();
        clearstatcache(true, $parte);
        $ricevuto = is_file($parte) ? (int) filesize($parte) : 0;

        if ($dimensione === 0 || $dimensione > self::DIMENSIONE_BLOCCO
            || (! $ultimo && $dimensione !== self::DIMENSIONE_BLOCCO)
            || $ricevuto !== $indice * self::DIMENSIONE_BLOCCO) {
            self::elimina($utente, $id);
            throw new \InvalidArgumentException('Caricamento interrotto: togli la foto e riprova.');
        }

        $in = fopen((string) $blocco->getRealPath(), 'rb');
        $out = fopen($parte, 'ab');
        $copiati = ($in !== false && $out !== false) ? stream_copy_to_stream($in, $out) : false;
        if ($in !== false) {
            fclose($in);
        }
        if ($out !== false) {
            fclose($out);
        }
        if ($copiati !== $dimensione) {
            self::elimina($utente, $id);
            throw new \InvalidArgumentException('Caricamento non riuscito: riprova.');
        }

        if (! $ultimo) {
            return ['completo' => false];
        }

        clearstatcache(true, $parte);
        if ((int) filesize($parte) > $maxByte) {
            self::elimina($utente, $id);
            throw new \InvalidArgumentException(self::messaggioTroppoGrande());
        }
        $mime = (string) (new MimeTypes)->guessMimeType($parte);
        if (! in_array($mime, self::mimeConsentiti(), true)) {
            self::elimina($utente, $id);
            throw new \InvalidArgumentException('Tipo di file non consentito.');
        }

        $dati['completo'] = true;
        self::scriviMeta($meta, $dati);

        return ['completo' => true];
    }

    /** Foto completa dell'utente come file caricato normale, o null se non c'è (scaduta, di un altro, incompleta). */
    public static function file(User $utente, string $id): ?UploadedFile
    {
        if (! Str::isUuid($id)) {
            return null;
        }
        [$parte, $meta] = self::percorsi($utente, $id);
        $dati = is_file($meta) ? json_decode((string) file_get_contents($meta), true) : null;
        if (! is_array($dati) || ! ($dati['completo'] ?? false) || ! is_file($parte)) {
            return null;
        }

        // test=true: il file non viene da is_uploaded_file(), ma è già stato controllato qui.
        return new UploadedFile($parte, (string) ($dati['nome'] ?? 'foto.jpg'), null, UPLOAD_ERR_OK, true);
    }

    public static function elimina(User $utente, string $id): void
    {
        if (! Str::isUuid($id)) {
            return;
        }
        foreach (self::percorsi($utente, $id) as $percorso) {
            @unlink($percorso);
        }
    }

    /** Cancella le foto più vecchie di ORE_VITA: di un utente, o di tutti. Restituisce quante. */
    public static function pulisci(?User $utente = null): int
    {
        $base = Storage::disk('local')->path(self::CARTELLA);
        $cartelle = $utente ? [self::cartella($utente)] : (glob($base.'/*', GLOB_ONLYDIR) ?: []);
        $limite = time() - self::ORE_VITA * 3600;
        $cancellate = 0;

        foreach ($cartelle as $cartella) {
            foreach (glob($cartella.'/*.json') ?: [] as $meta) {
                if ((int) @filemtime($meta) < $limite) {
                    @unlink($meta);
                    @unlink(substr($meta, 0, -5).'.part');
                    $cancellate++;
                }
            }
            // Blocchi rimasti senza meta (scrittura interrotta).
            foreach (glob($cartella.'/*.part') ?: [] as $parte) {
                if (! is_file(substr($parte, 0, -5).'.json') && (int) @filemtime($parte) < $limite) {
                    @unlink($parte);
                }
            }
            if (! $utente && (glob($cartella.'/*') ?: []) === []) {
                @rmdir($cartella);
            }
        }

        return $cancellate;
    }

    private static function cartella(User $utente): string
    {
        return Storage::disk('local')->path(self::CARTELLA.'/'.(int) $utente->id);
    }

    /** @return array{0: string, 1: string} */
    private static function percorsi(User $utente, string $id): array
    {
        $base = self::cartella($utente).'/'.strtolower($id);

        return [$base.'.part', $base.'.json'];
    }

    /** @param  array<string, mixed>  $dati */
    private static function scriviMeta(string $meta, array $dati): void
    {
        file_put_contents($meta, json_encode($dati), LOCK_EX);
    }

    private static function nomePulito(string $nome): string
    {
        $nome = trim(str_replace(['/', '\\', "\0"], '', $nome));

        return $nome === '' ? 'foto.jpg' : Str::limit($nome, 180, '');
    }

    private static function maxByte(): int
    {
        return max(1, (int) Impostazione::get('allegati_max_size_mb', 10)) * 1048576;
    }

    private static function messaggioTroppoGrande(): string
    {
        return 'Ogni file non può superare '.(int) Impostazione::get('allegati_max_size_mb', 10).' MB.';
    }

    /** @return list<string> */
    private static function mimeConsentiti(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) Impostazione::get('allegati_mime_consentiti', 'image/jpeg,image/png')))));
    }
}
