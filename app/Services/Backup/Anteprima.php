<?php

namespace App\Services\Backup;

/** Riepilogo di un backup prima del ripristino: cosa contiene e se si può procedere. */
final readonly class Anteprima
{
    /**
     * @param  array<string,mixed>  $manifest
     * @param  list<string>  $migrazioniSconosciute
     */
    public function __construct(
        public array $manifest,
        public bool $compatibile,
        public array $migrazioniSconosciute,
        public bool $installazioneVuota,
        /** Si salva lo stato attuale prima di procedere (sempre, se c'è la passphrase locale). */
        public bool $preRipristino,
        public bool $stessaAppKey,
        public bool $stessoAppUrl,
        public ?string $bloccante,
    ) {}
}
