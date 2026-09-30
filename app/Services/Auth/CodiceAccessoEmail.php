<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Notifications\CodiceAccessoNotification;
use Illuminate\Support\Facades\Cache;

/**
 * Secondo fattore via email per gli account locali (ditte): nessuna app da
 * installare. In cache solo l'hash; scadenza fissa dalla generazione (gli
 * errori non la allungano).
 */
final class CodiceAccessoEmail
{
    public const MINUTI_VALIDITA = 10;

    public const MAX_TENTATIVI = 5;

    public function invia(User $user): void
    {
        $codice = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $scade = now()->addMinutes(self::MINUTI_VALIDITA);

        Cache::put($this->chiave($user), [
            'hash' => hash('sha256', $codice),
            'tentativi' => 0,
            'scade' => $scade->getTimestamp(),
        ], $scade);

        $user->notify(new CodiceAccessoNotification($codice, self::MINUTI_VALIDITA));
    }

    public function verifica(User $user, string $codice): bool
    {
        $chiave = $this->chiave($user);
        $dati = Cache::get($chiave);

        if (! is_array($dati) || $dati['scade'] <= now()->getTimestamp()) {
            Cache::forget($chiave);

            return false;
        }

        if (hash_equals($dati['hash'], hash('sha256', trim($codice)))) {
            Cache::forget($chiave);

            return true;
        }

        $dati['tentativi']++;

        if ($dati['tentativi'] >= self::MAX_TENTATIVI) {
            Cache::forget($chiave);
        } else {
            Cache::put($chiave, $dati, now()->setTimestamp($dati['scade']));
        }

        return false;
    }

    private function chiave(User $user): string
    {
        return '2fa-email:'.$user->getKey();
    }
}
