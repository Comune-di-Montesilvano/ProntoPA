<?php

namespace App\Notifications\Concerns;

use App\Models\Impostazione;
use Illuminate\Notifications\Messages\MailMessage;

trait BuildsNotificationMailMessage
{
    protected function baseMailMessage(string $subject): MailMessage
    {
        return (new MailMessage)
            ->from(
                Impostazione::get('mail_from_address', config('mail.from.address')),
                Impostazione::get('mail_from_name', config('mail.from.name')),
            )
            ->subject($subject)
            ->greeting('Buongiorno,');
    }
}