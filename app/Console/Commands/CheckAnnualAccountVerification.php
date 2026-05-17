<?php

namespace App\Console\Commands;

use App\Models\Impostazione;
use App\Models\User;
use App\Notifications\AnnualVerificationReminderNotification;
use App\Notifications\AnnualVerificationRequestNotification;
use App\Notifications\AnnualVerificationSuspendedNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class CheckAnnualAccountVerification extends Command
{
    protected $signature = 'accounts:annual-check {--dry-run : Simula senza inviare notifiche e senza aggiornare dati}';

    protected $description = 'Invia verifica annuale account e sospende automaticamente gli account non confermati';

    public function handle(): int
    {
        $enabled = filter_var(Impostazione::get('account_verification_enabled', '1'), FILTER_VALIDATE_BOOLEAN);
        if (! $enabled) {
            $this->info('Verifica annuale account disabilitata da impostazioni.');
            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        $annualDays = max((int) Impostazione::get('account_verification_annual_days', 365), 1);
        $tokenDays = max((int) Impostazione::get('account_verification_token_days', 30), 1);
        $reminderDays = max((int) Impostazione::get('account_verification_reminder_days', 15), 1);
        $autoSuspend = filter_var(Impostazione::get('account_verification_auto_suspend', '1'), FILTER_VALIDATE_BOOLEAN);

        $now = now();

        $toSend = User::query()
            ->where('attivo', true)
            ->where('approval_status', 'approved')
            ->whereNull('annual_verification_sent_at')
            ->where(function ($query) use ($now) {
                $query->whereNull('prossima_verifica_annuale_at')
                    ->orWhere('prossima_verifica_annuale_at', '<=', $now);
            })
            ->get();

        $sent = 0;

        foreach ($toSend as $user) {
            $token = Str::random(64);
            $dueAt = $now->copy()->addDays($tokenDays);
            $verificationUrl = URL::temporarySignedRoute(
                'account.verification.annual',
                $dueAt,
                ['user' => $user->id, 'token' => $token]
            );

            if (! $dryRun) {
                $user->update([
                    'annual_verification_token_hash' => hash('sha256', $token),
                    'annual_verification_sent_at' => $now,
                    'annual_verification_due_at' => $dueAt,
                    'annual_verification_reminder_at' => null,
                ]);

                $user->notify(new AnnualVerificationRequestNotification(
                    verificationUrl: $verificationUrl,
                    scadenza: $dueAt->format('d/m/Y H:i')
                ));
            }

            $sent++;
        }

        $toRemind = User::query()
            ->where('attivo', true)
            ->where('approval_status', 'approved')
            ->whereNotNull('annual_verification_token_hash')
            ->whereNotNull('annual_verification_due_at')
            ->whereNull('annual_verification_reminder_at')
            ->where('annual_verification_due_at', '>', $now)
            ->where('annual_verification_due_at', '<=', $now->copy()->addDays($reminderDays))
            ->get();

        $reminded = 0;

        foreach ($toRemind as $user) {
            $token = Str::random(64);
            $dueAt = $user->annual_verification_due_at;
            $verificationUrl = URL::temporarySignedRoute(
                'account.verification.annual',
                $dueAt,
                ['user' => $user->id, 'token' => $token]
            );

            if (! $dryRun) {
                $user->update([
                    'annual_verification_token_hash' => hash('sha256', $token),
                    'annual_verification_reminder_at' => $now,
                ]);

                $user->notify(new AnnualVerificationReminderNotification(
                    verificationUrl: $verificationUrl,
                    scadenza: $dueAt->format('d/m/Y H:i')
                ));
            }

            $reminded++;
        }

        $suspended = 0;

        if ($autoSuspend) {
            $toSuspend = User::query()
                ->where('attivo', true)
                ->where('approval_status', 'approved')
                ->whereNotNull('annual_verification_token_hash')
                ->whereNotNull('annual_verification_due_at')
                ->where('annual_verification_due_at', '<=', $now)
                ->get();

            foreach ($toSuspend as $user) {
                if (! $dryRun) {
                    $user->update([
                        'attivo' => false,
                    ]);

                    $user->notify(new AnnualVerificationSuspendedNotification());
                }

                $suspended++;
            }
        }

        $this->info("Verifica annuale: invii={$sent}, reminder={$reminded}, sospesi={$suspended}".
            ($dryRun ? ' (dry-run)' : ''));

        if (! $dryRun && $annualDays > 0) {
            User::query()
                ->where('approval_status', 'approved')
                ->whereNull('prossima_verifica_annuale_at')
                ->update(['prossima_verifica_annuale_at' => now()->addDays($annualDays)]);
        }

        return self::SUCCESS;
    }
}
