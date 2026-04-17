<?php

namespace App\Events;

use App\Models\Segnalazione;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SegnalazionePublishedAutomatically
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Segnalazione $segnalazione,
        public int $previousStateId,
        public int $newStateId,
    ) {}
}
