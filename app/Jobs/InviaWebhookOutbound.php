<?php

namespace App\Jobs;

use App\Models\Segnalazione;
use App\Services\WebhookService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class InviaWebhookOutbound implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public readonly Segnalazione $segnalazione) {}

    public function handle(WebhookService $service): void
    {
        $service->notificaCambioStato($this->segnalazione);
    }
}
