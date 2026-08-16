<?php

namespace App\Providers;

use App\Models\Impostazione;
use Illuminate\Support\ServiceProvider;

class ImpostazioniMailServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        try {
            $smtpHost = Impostazione::get('smtp_host');

            if ($smtpHost) {
                config([
                    'mail.mailers.smtp.host'     => $smtpHost,
                    'mail.mailers.smtp.port'     => (int) Impostazione::get('smtp_port', 587),
                    'mail.mailers.smtp.username' => Impostazione::get('smtp_username'),
                    'mail.mailers.smtp.password' => Impostazione::get('smtp_password'),
                    'mail.mailers.smtp.scheme'   => Impostazione::get('smtp_encryption') ?: null,
                ]);
            }
        } catch (\Throwable) {
            // DB non disponibile (migration non ancora eseguita, ecc.)
        }
    }
}
