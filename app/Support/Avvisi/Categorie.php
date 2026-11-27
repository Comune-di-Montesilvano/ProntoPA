<?php

namespace App\Support\Avvisi;

use App\Models\Impostazione;

/** Categorie di avviso raggruppabili in un riepilogo, con il ritmo scelto dall'admin (ora | giorno). */
final class Categorie
{
    public const RITMO_ORA = 'ora';

    public const RITMO_GIORNO = 'giorno';

    /** Ordine delle sezioni nel riepilogo. */
    public const ORDINE = ['assegnazioni', 'solleciti', 'richieste', 'lavoro', 'scadenze', 'nuove', 'inattivita', 'esiti'];

    /** @var array<string, string> */
    public const DEFAULT = [
        'assegnazioni' => self::RITMO_ORA,
        'solleciti'    => self::RITMO_ORA,
        'richieste'    => self::RITMO_ORA,
        'lavoro'       => self::RITMO_ORA,
        'scadenze'     => self::RITMO_GIORNO,
        'nuove'        => self::RITMO_GIORNO,
        'inattivita'   => self::RITMO_GIORNO,
        'esiti'        => self::RITMO_GIORNO,
    ];

    private const ETICHETTE = [
        'assegnazioni' => 'Assegnate a te',
        'solleciti'    => 'Solleciti',
        'richieste'    => 'Richieste di informazioni',
        'lavoro'       => 'Da seguire',
        'scadenze'     => 'Scadenze',
        'nuove'        => 'Nuove',
        'inattivita'   => 'Ferme o archiviate',
        'esiti'        => 'Esiti',
    ];

    public static function etichetta(string $categoria): string
    {
        return self::ETICHETTE[$categoria] ?? $categoria;
    }

    /** Ritmo configurato; valore sconosciuto o assente = default della categoria. */
    public static function ritmo(string $categoria): string
    {
        $default = self::DEFAULT[$categoria] ?? self::RITMO_GIORNO;
        $valore = Impostazione::get("avvisi_ritmo_$categoria", $default);

        return in_array($valore, [self::RITMO_ORA, self::RITMO_GIORNO], true) ? $valore : $default;
    }
}
