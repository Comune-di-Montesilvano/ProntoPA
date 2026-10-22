<?php

namespace App\Services\Backup;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Avanzamento ed esito di un ripristino, su file nel volume dei backup:
 * il DB e la cache vengono sostituiti durante il ripristino, il file no.
 */
class StatoRipristino
{
    public static function nuovoToken(): string
    {
        return Str::random(40);
    }

    public static function fase(string $token, string $fase, string $messaggio = ''): void
    {
        self::scrivi($token, ['fase' => $fase, 'esito' => null, 'messaggio' => $messaggio]);
    }

    public static function fine(string $token, bool $esito, string $messaggio): void
    {
        self::scrivi($token, ['fase' => $esito ? 'completato' : 'fallito', 'esito' => $esito, 'messaggio' => $messaggio]);
    }

    /** Se il processo muore a metà, il job sa quale backup indicare per tornare indietro. */
    public static function preRipristino(string $token, string $nome): void
    {
        $dati = self::leggi($token) ?? ['fase' => 'pre-ripristino', 'esito' => null, 'messaggio' => ''];
        self::scrivi($token, ['pre_ripristino' => $nome] + $dati);
    }

    /** @return array{fase:string,esito:?bool,messaggio:string,aggiornato_il:string,pre_ripristino?:string}|null */
    public static function leggi(string $token): ?array
    {
        $file = self::file($token);
        if (! is_file($file)) {
            return null;
        }
        $dati = json_decode((string) file_get_contents($file), true);

        return is_array($dati) ? $dati : null;
    }

    /** @param array{fase:string,esito:?bool,messaggio:string,pre_ripristino?:string} $dati */
    private static function scrivi(string $token, array $dati): void
    {
        $file = self::file($token);
        $prima = self::leggi($token);
        if (isset($prima['pre_ripristino']) && ! isset($dati['pre_ripristino'])) {
            $dati['pre_ripristino'] = $prima['pre_ripristino'];
        }
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        $dati['aggiornato_il'] = now()->toIso8601String();

        // Scrittura atomica: chi fa polling non deve mai leggere un JSON a metà.
        $tmp = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
        file_put_contents($tmp, json_encode($dati, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        rename($tmp, $file);
    }

    private static function file(string $token): string
    {
        if (! preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            throw new InvalidArgumentException('Token di ripristino non valido.');
        }

        return rtrim((string) config('backup.path'), '/\\').'/stato/'.$token.'.json';
    }
}
