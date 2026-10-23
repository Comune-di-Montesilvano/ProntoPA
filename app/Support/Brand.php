<?php

namespace App\Support;

use App\Models\Impostazione;
use Illuminate\Support\Facades\Storage;

/**
 * Unico punto che risolve i loghi (web ed email). Logo ente: file caricato
 * da Admin → Impostazioni, altrimenti `ente_logo_url`, altrimenti nessuno.
 */
class Brand
{
    public const DIR_LOGO = 'brand';

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
}
