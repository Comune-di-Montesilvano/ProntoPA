<?php

namespace App\Support;

use App\Models\Impostazione;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Unico punto che risolve i loghi (web ed email). Logo ente: file caricato
 * da Admin → Impostazioni, altrimenti `ente_logo_url`, altrimenti nessuno.
 */
class Brand
{
    public const DIR_LOGO = 'brand';

    /** Il template è renderizzato più volte per email (html, testo): un solo embed per messaggio. */
    private static ?\WeakMap $embedPerMessaggio = null;

    public static function logoEnteFile(): ?string
    {
        $relativo = Impostazione::get('ente_logo_file');

        if (blank($relativo) || ! Storage::disk('local')->exists($relativo)) {
            return null;
        }

        return Storage::disk('local')->path($relativo);
    }

    public static function logoEnteUrl(): ?string
    {
        $file = self::logoEnteFile();
        if ($file !== null) {
            // ?v= cambia a ogni upload: niente logo vecchio dalla cache del browser.
            return route('brand.logo-ente', ['v' => filemtime($file)]);
        }

        $url = Impostazione::get('ente_logo_url');

        return filled($url) ? url($url) : null;
    }

    public static function logoProntoPaEmail(): string
    {
        return resource_path('brand/prontopa-email.png');
    }

    /**
     * Path locale del logo ente da incorporare nelle email: file caricato,
     * altrimenti copia in cache dell'URL. Mai un errore: senza logo → null.
     */
    public static function logoEntePerEmail(): ?string
    {
        $file = self::logoEnteFile();
        if ($file !== null) {
            return $file;
        }

        $url = trim((string) Impostazione::get('ente_logo_url'));
        if ($url === '') {
            return null;
        }

        if (! preg_match('#^https?://#i', $url)) {
            $locale = realpath(public_path(ltrim($url, '/')));

            return $locale !== false && str_starts_with($locale, realpath(public_path())) && self::immagine((string) file_get_contents($locale)) !== null
                ? $locale
                : null;
        }

        return self::scaricaInCache($url);
    }

    /**
     * Loghi dell'header email come src "cid:" (ente se c'è, poi ProntoPA).
     * Senza un messaggio (anteprima in browser) nessuna immagine.
     *
     * @return list<array{src: string, alt: string}>
     */
    public static function loghiEmail(mixed $message): array
    {
        if (! is_object($message) || ! method_exists($message, 'embed')) {
            return [];
        }

        self::$embedPerMessaggio ??= new \WeakMap();
        if (isset(self::$embedPerMessaggio[$message])) {
            return self::$embedPerMessaggio[$message];
        }

        $loghi = [];
        $ente = self::logoEntePerEmail();
        if ($ente !== null) {
            $loghi[] = ['src' => $message->embed($ente), 'alt' => (string) Impostazione::get('ente_nome', 'ProntoPA')];
        }
        $loghi[] = ['src' => $message->embed(self::logoProntoPaEmail()), 'alt' => 'ProntoPA'];

        return self::$embedPerMessaggio[$message] = $loghi;
    }

    private static function scaricaInCache(string $url): ?string
    {
        $hash = sha1($url);
        $dir = storage_path('framework/cache/brand');

        foreach (glob("{$dir}/{$hash}.*") ?: [] as $esistente) {
            return $esistente;
        }

        // Download fallito di recente: nessun nuovo tentativo per un'ora.
        if (Cache::has("brand:logo-ente-ko:{$hash}")) {
            return null;
        }

        $corpo = '';
        try {
            $risposta = Http::timeout(5)->get($url);
            $corpo = $risposta->successful() ? $risposta->body() : '';
            $ext = strlen($corpo) <= 1024 * 1024 ? self::immagine($corpo) : null;
        } catch (Throwable) {
            $ext = null;
        }

        if ($ext === null) {
            Cache::put("brand:logo-ente-ko:{$hash}", true, 3600);

            return null;
        }

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path = "{$dir}/{$hash}.{$ext}", $corpo);

        return $path;
    }

    /** Estensione se i byte sono un'immagine adatta alle email, altrimenti null. */
    private static function immagine(string $byte): ?string
    {
        $info = $byte !== '' ? @getimagesizefromstring($byte) : false;

        return match ($info[2] ?? null) {
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_GIF => 'gif',
            IMAGETYPE_WEBP => 'webp',
            default => null,
        };
    }
}
