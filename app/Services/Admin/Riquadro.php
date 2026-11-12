<?php

namespace App\Services\Admin;

/** Un riquadro della home admin: una cosa da tenere d'occhio, con il suo stato. */
final readonly class Riquadro
{
    public const OK = 'ok';

    public const ATTENZIONE = 'attenzione';

    public const PROBLEMA = 'problema';

    public const NON_VERIFICABILE = 'non_verificabile';

    public function __construct(
        public string $chiave,
        public string $titolo,
        /** ok|attenzione|problema|non_verificabile */
        public string $stato,
        public string $messaggio,
        public string $url,
    ) {}
}
