<?php

namespace App\Providers;

use App\Models\Impostazione;
use App\Models\Segnalazione;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use App\Policies\SegnalazionePolicy;
use App\Services\Directory\Directory;
use App\Services\Directory\LdapConfigGuard;
use App\Services\Directory\LdapRecordDirectory;
use App\Services\Directory\MockDirectory;
use App\Services\Directory\NullDirectory;
use App\Services\Oidc\OidcMockGuard;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Directory::class, function () {
            $host = config('ldap.host');

            return match (true) {
                $host === 'mock' => new MockDirectory(),
                blank($host) => new NullDirectory(),
                default => new LdapRecordDirectory(config('ldap')),
            };
        });
    }

    public function boot(): void
    {
        // Un solo punto per tutti i metodi di accesso (AD, SPID):
        // la sessione ricorda a quale ripristino appartiene (InvalidaSessioniDopoRipristino).
        Event::listen(Login::class, function (): void {
            session()->put('ripristino_id', Impostazione::ripristinoId());
        });

        LdapConfigGuard::verifica((string) $this->app->environment(), config('ldap.host'));
        OidcMockGuard::verifica((string) $this->app->environment(), (bool) config('oidc.mock'));

        $ambiente = (string) $this->app->environment();
        $avvisi = [
            LdapConfigGuard::avviso($ambiente, (bool) config('ldap.tls_skip_verify')),
            LdapConfigGuard::avvisoTemplate($ambiente, config('ldap.host'), (string) config('ldap.user_dn_template')),
        ];

        foreach (array_filter($avvisi) as $avviso) {
            Log::warning($avviso);
        }

        // Il rebuild settimanale ha la stessa release del tag: i tag distinguono le immagini.
        \Sentry\configureScope(fn (\Sentry\State\Scope $scope) => $scope->setTags(array_filter([
            'build_type' => (string) config('app.build_type'),
            'build_date' => (string) config('app.build_date'),
            'commit'     => substr((string) config('app.commit'), 0, 7),
        ])));

        Gate::policy(Segnalazione::class, SegnalazionePolicy::class);

        // Prima email che il personale delle scuole riceve dall'ente: in
        // italiano, altrimenti sembra phishing.
        VerifyEmail::toMailUsing(fn (object $notifiable, string $url) => (new MailMessage)
            ->subject('Conferma il tuo indirizzo email')
            ->line('Hai effettuato il primo accesso a '.Impostazione::get('ente_nome', 'ProntoPA').' con SPID o CIE.')
            ->line('Conferma questo indirizzo per ricevere gli aggiornamenti delle tue segnalazioni.')
            ->action('Conferma email', $url)
            ->line('Se non sei stato tu, ignora questa email.'));

        // Unica fonte di verità per la policy password: prima d'ora
        // registrazione e cambio password usavano il default Laravel puro (8 caratteri,
        // zero complessità) — incoerente per un sistema con account
        // gestori/imprese su dati di minori.
        Password::defaults(fn () => Password::min(10)->mixedCase()->numbers());
    }
}
