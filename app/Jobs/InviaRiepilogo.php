<?php

namespace App\Jobs;

use App\Channels\TelegramChannel;
use App\Models\AvvisoInAttesa;
use App\Models\Impostazione;
use App\Models\User;
use App\Notifications\RiepilogoAvvisiNotification;
use App\Services\Avvisi\ComponiRiepilogo;
use App\Services\Avvisi\Composizione;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Invia il riepilogo di un destinatario per le righe prenotate dal giro (`giro`). Le righe si marcano
 * inviate solo a invio riuscito; invio fallito → righe liberate per il giro dopo, alla terza volta
 * scartate. Non rilancia mai: niente failed_jobs (l'errore si vede in log e nello Stato del sistema).
 */
class InviaRiepilogo implements ShouldQueue
{
    use Queueable;

    public const MAX_TENTATIVI = 3;

    public int $tries = 1;

    public function __construct(
        public readonly string $giro,
        public readonly string $chiave,
        public readonly bool $mattino,
    ) {}

    public function handle(): void
    {
        /** @var Collection<int, AvvisoInAttesa> $righe */
        $righe = AvvisoInAttesa::query()->where('giro', $this->giro)->inAttesa()->get();

        // Senza righe c'è da inviare solo il quadro del mattino.
        if ($righe->isEmpty() && ! $this->mattino) {
            return;
        }

        $chi = AvvisoInAttesa::notificabileDaChiave($this->chiave);
        if ($chi === null) {
            // Utente disattivato o sparito: nessun invio, le righe non restano in attesa per sempre.
            $this->marcaInviate($righe->pluck('id')->all());

            return;
        }

        try {
            $composizione = ComponiRiepilogo::componi($righe, $chi, $this->mattino);

            if (! $composizione->vuota()) {
                $this->invia($composizione, $chi);
            }

            $this->marcaInviate([...$composizione->idRigheUsate, ...$righe->pluck('id')->all()]);
        } catch (Throwable $e) {
            $this->fallito($righe, $e);
        }
    }

    private function invia(Composizione $c, User|AnonymousNotifiable $chi): void
    {
        $unica = $c->unica;
        if (! $unica instanceof Notification) {
            $chi->notifyNow(new RiepilogoAvvisiNotification($c, $this->mattino));

            return;
        }

        // Un solo avviso: l'email di sempre, solo in ritardo; su Telegram il riepilogo a una riga.
        if (property_exists($unica, 'daRiepilogo')) {
            $unica->daRiepilogo = true;
        }
        $chi->notifyNow($unica, ['mail']);

        if ($chi instanceof User && $chi->routeNotificationForTelegram() && Impostazione::get('telegram_riepiloghi', true)) {
            $chi->notifyNow(
                new RiepilogoAvvisiNotification(new Composizione($c->quadro, $c->sezioni, $c->idRigheUsate, null), $this->mattino),
                [TelegramChannel::class],
            );
        }
    }

    /** @param  array<int, int|string>  $id */
    private function marcaInviate(array $id): void
    {
        if ($id === []) {
            return;
        }

        AvvisoInAttesa::query()
            ->whereIn('id', array_values(array_unique(array_map('intval', $id))))
            ->whereNull('inviata_at')
            ->update(['inviata_at' => now()]);
    }

    /** @param  Collection<int, AvvisoInAttesa>  $righe */
    private function fallito(Collection $righe, Throwable $e): void
    {
        if ($righe->isEmpty()) {
            Log::warning('Quadro del mattino non inviato', ['destinatario' => $this->chiave, 'errore' => $e->getMessage()]);

            return;
        }

        $id = $righe->pluck('id')->all();
        AvvisoInAttesa::query()->whereIn('id', $id)->increment('tentativi', 1, ['giro' => null]);

        $scartate = AvvisoInAttesa::query()->whereIn('id', $id)->inAttesa()
            ->where('tentativi', '>=', self::MAX_TENTATIVI)->pluck('id')->all();
        if ($scartate === []) {
            return;
        }

        AvvisoInAttesa::query()->whereIn('id', $scartate)->update(['scartata_at' => now()]);
        Log::warning('Riepilogo avvisi scartato', [
            'destinatario' => $this->chiave,
            'righe'        => count($scartate),
            'tentativi'    => self::MAX_TENTATIVI,
            'errore'       => $e->getMessage(),
        ]);
    }
}
