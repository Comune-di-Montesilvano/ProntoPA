<?php

namespace App\Support;

/** Nomi salvati in maiuscolo (sedi, tipologie) resi leggibili: "VERDE E GIARDINI" → "Verde e Giardini". */
final class NomeLeggibile
{
    private const MINUSCOLE = ['e', 'di', 'a', 'da', 'del', 'della', 'dei', 'per', 'in'];

    public static function di(?string $nome): string
    {
        $nome = trim((string) $nome);
        if ($nome === '' || mb_strtoupper($nome) !== $nome) {
            return $nome; // già scritto a mano con le sue maiuscole
        }

        return (string) preg_replace_callback(
            '/(^|[\s\-\'"(.\/])(\p{L}+)/u',
            function (array $m): string {
                $parola = mb_strtolower($m[2]);
                if ($m[1] !== '' && in_array($parola, self::MINUSCOLE, true)) {
                    return $m[1].$parola;
                }

                return $m[1].mb_strtoupper(mb_substr($parola, 0, 1)).mb_substr($parola, 1);
            },
            $nome
        );
    }
}
