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
        public bool $stessaAppKey,
        public bool $stessoAppUrl,
        public ?string $bloccante,
    ) {}
}
