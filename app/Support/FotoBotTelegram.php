<?php

namespace App\Support;

/**
 * Foto del bot Telegram (1.0.11) dal logo dell'ente (o di ProntoPA): JPG quadrato 640 px su
 * fondo bianco, come vuole setMyProfilePhoto. Mai un errore: immagine illeggibile → null.
 */
class FotoBotTelegram
{
    public const LATO = 640;

    /** @return array{jpeg: string, hash: string}|null hash del file sorgente, per ricaricare solo se cambia */
    public static function daLogo(): ?array
    {
        $percorso = Brand::logoEntePerEmail() ?? Brand::logoProntoPaEmail();
        $sorgente = @file_get_contents($percorso);
        if ($sorgente === false || $sorgente === '') {
            return null;
        }

        $logo = @imagecreatefromstring($sorgente);
        if ($logo === false) {
            return null;
        }

        $tela = imagecreatetruecolor(self::LATO, self::LATO);
        imagefill($tela, 0, 0, (int) imagecolorallocate($tela, 255, 255, 255));

        // Logo intero al centro, proporzioni rispettate; 68% del lato perché Telegram ritaglia la foto in un cerchio.
        $spazio = (int) round(self::LATO * 0.68);
        $larghezza = imagesx($logo);
        $altezza = imagesy($logo);
        $scala = min($spazio / $larghezza, $spazio / $altezza);
        $w = max(1, (int) round($larghezza * $scala));
        $h = max(1, (int) round($altezza * $scala));
        imagecopyresampled($tela, $logo, intdiv(self::LATO - $w, 2), intdiv(self::LATO - $h, 2), 0, 0, $w, $h, $larghezza, $altezza);

        ob_start();
        imagejpeg($tela, null, 90);
        $jpeg = (string) ob_get_clean();

        return $jpeg === '' ? null : ['jpeg' => $jpeg, 'hash' => sha1($sorgente)];
    }
}
