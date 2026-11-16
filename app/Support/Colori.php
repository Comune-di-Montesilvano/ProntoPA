<?php

namespace App\Support;

use App\Enums\SegnalazioneStato;

/**
 * Unica fonte dei colori di stati, tipologie e priorità (1.0.4).
 * Il layout emette da qui le variabili CSS (.pa-tono-*), i grafici leggono gli stessi esadecimali.
 * Segni diversi per non confonderli: stato = pallino, tipologia = sfondo pieno, priorità = etichetta.
 */
final class Colori
{
    /** @var array<string, array{sfondo: string, testo: string, punto: string}> */
    public const TONI = [
        'verde'   => ['sfondo' => '#dcfce7', 'testo' => '#166534', 'punto' => '#16a34a'],
        'salvia'  => ['sfondo' => '#ecfccb', 'testo' => '#3f6212', 'punto' => '#65a30d'],
        'blu'     => ['sfondo' => '#dbeafe', 'testo' => '#1e40af', 'punto' => '#2563eb'],
        'azzurro' => ['sfondo' => '#e0f2fe', 'testo' => '#075985', 'punto' => '#0284c7'],
        'giallo'  => ['sfondo' => '#fef9c3', 'testo' => '#854d0e', 'punto' => '#ca8a04'],
        'rosso'   => ['sfondo' => '#fee2e2', 'testo' => '#991b1b', 'punto' => '#dc2626'],
        'arancio' => ['sfondo' => '#ffedd5', 'testo' => '#9a3412', 'punto' => '#ea580c'],
        'marrone' => ['sfondo' => '#f5e6d3', 'testo' => '#78350f', 'punto' => '#a16207'],
        'pietra'  => ['sfondo' => '#e7e5e4', 'testo' => '#44403c', 'punto' => '#78716c'],
        'viola'   => ['sfondo' => '#ede9fe', 'testo' => '#5b21b6', 'punto' => '#7c3aed'],
        'rosa'    => ['sfondo' => '#fce7f3', 'testo' => '#9d174d', 'punto' => '#db2777'],
        'indaco'  => ['sfondo' => '#e0e7ff', 'testo' => '#3730a3', 'punto' => '#4f46e5'],
        'grigio'  => ['sfondo' => '#f1f5f9', 'testo' => '#475569', 'punto' => '#94a3b8'],
    ];

    /** Etichette per la scelta in Admin → Tipologie. */
    public const NOMI = [
        'verde' => 'Verde', 'salvia' => 'Verde salvia', 'blu' => 'Blu', 'azzurro' => 'Azzurro',
        'giallo' => 'Giallo', 'rosso' => 'Rosso', 'arancio' => 'Arancio', 'marrone' => 'Marrone',
        'pietra' => 'Pietra', 'viola' => 'Viola', 'rosa' => 'Rosa', 'indaco' => 'Indaco', 'grigio' => 'Grigio',
    ];

    /** Nuova aspetta qualcuno · blu = in lavorazione · giallo = ferma · verde = fatto · grigio = chiusa senza lavoro. */
    public static function stato(SegnalazioneStato $stato): string
    {
        return match ($stato) {
            SegnalazioneStato::NUOVA => 'arancio',
            SegnalazioneStato::IN_CARICO => 'azzurro',
            SegnalazioneStato::ASSEGNATA_OPERATORE => 'blu',
            SegnalazioneStato::ASSEGNATA_IMPRESA => 'indaco',
            SegnalazioneStato::PREVENTIVO_IN_ATTESA, SegnalazioneStato::SOSPESA, SegnalazioneStato::ATTESA_RISPOSTA => 'giallo',
            SegnalazioneStato::COMPLETATA => 'verde',
            SegnalazioneStato::DUPLICATA, SegnalazioneStato::ANNULLATA, SegnalazioneStato::ARCHIVIATA => 'grigio',
        };
    }

    /** L'urgenza è rossa; Media non ha colore (negli elenchi non si mostra). */
    public static function priorita(int $livello): ?string
    {
        return match ($livello) {
            3, 4 => 'rosso',
            1 => 'grigio',
            default => null,
        };
    }

    /** @return array{sfondo: string, testo: string, punto: string} */
    public static function tono(?string $nome): array
    {
        return self::TONI[$nome ?? 'grigio'] ?? self::TONI['grigio'];
    }

    public static function css(): string
    {
        $css = '';
        foreach (self::TONI as $nome => $t) {
            $css .= ".pa-tono-{$nome}{--tono-sfondo:{$t['sfondo']};--tono-testo:{$t['testo']};--tono-punto:{$t['punto']}}";
        }

        return $css;
    }
}
