<?php

namespace App\Services\Admin;

use App\Models\Delega;
use App\Models\Impostazione;
use App\Services\Auth\EsitoSyncAd;
use App\Services\Auth\SincronizzaUtentiAd;
use App\Services\Backup\CatalogoBackup;
use App\Services\Directory\Directory;
use App\Services\Oidc\OidcConfig;
use App\Services\Scuole\AnagrafeMiur;
use App\Services\Segnalazioni\CoperturaTipologie;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Cosa mostrare nella home admin. Nessuna chiamata di rete: per le integrazioni
 * con un servizio esterno (Telegram, assistente AI, antivirus) conta solo l'esito
 * dell'ultima prova fatta dalla pagina Integrazioni.
 */
class StatoSistema
{
    private const ORE_SYNC_FRESCA = 36;

    private const GIORNI_MIUR_VECCHIO = 365;

    public function __construct(
        private readonly SincronizzaUtentiAd $sync,
        private readonly Directory $directory,
        private readonly CatalogoBackup $backup,
        private readonly OidcConfig $oidc,
        private readonly CoperturaTipologie $copertura,
        private readonly AnagrafeMiur $miur,
    ) {}

    /** @return list<Riquadro> */
    public function riquadri(): array
    {
        $riquadri = [
            $this->sync(),
            $this->backup(),
            $this->spid(),
            $this->email(),
            $this->webhook(),
            $this->miur(),
            $this->orfane(),
            $this->deleghe(),
            $this->sedi(),
            $this->conProva('telegram', 'Telegram', filled(Impostazione::get('telegram_bot_token'))),
            $this->conProva('ai', 'Assistente AI', (bool) Impostazione::get('ai_enabled', false)),
            $this->conProva('antivirus', 'Antivirus allegati', (bool) Impostazione::get('antivirus_enabled', false)),
        ];

        return array_values(array_filter($riquadri));
    }

    /** @return list<string> titoli delle funzioni facoltative oggi spente */
    public function spente(): array
    {
        $spente = [];
        if (! $this->spidMostrato()) {
            $spente[] = 'Accesso SPID/CIE';
        }
        if (! filled(Impostazione::get('webhook_cittadini_url'))) {
            $spente[] = 'Avvisi al sito del comune';
        }
        if (! filled(Impostazione::get('telegram_bot_token'))) {
            $spente[] = 'Telegram';
        }
        if (! Impostazione::get('ai_enabled', false)) {
            $spente[] = 'Assistente AI';
        }
        if (! Impostazione::get('antivirus_enabled', false)) {
            $spente[] = 'Antivirus allegati';
        }

        return $spente;
    }

    private function sync(): ?Riquadro
    {
        if (! $this->directory->syncConfigurata()) {
            return null;
        }
        $titolo = 'Sincronizzazione utenti del dominio';
        $url = route('admin.utenti.dominio');
        $esito = $this->sync->ultimoEsito();

        if ($esito === null) {
            return new Riquadro('sync', $titolo, Riquadro::PROBLEMA, 'Non è ancora stata eseguita: lancia "Sincronizza ora".', $url);
        }
        if ($esito->stato === EsitoSyncAd::FALLITA) {
            return new Riquadro('sync', $titolo, Riquadro::PROBLEMA, "L'ultima sincronizzazione è fallita".($esito->motivo ? ': '.$esito->motivo : '.'), $url);
        }
        if ($esito->stato === EsitoSyncAd::BLOCCATA) {
            return new Riquadro('sync', $titolo, Riquadro::ATTENZIONE, "L'ultima sincronizzazione è stata fermata per sicurezza: troppe disattivazioni in un colpo. Controlla e conferma.", $url);
        }
        if ($esito->quando->lt(now()->subHours(self::ORE_SYNC_FRESCA))) {
            return new Riquadro('sync', $titolo, Riquadro::PROBLEMA, 'Non gira da più di un giorno e mezzo (ultima: '.$esito->quando->timezone('Europe/Rome')->format('d/m/Y H:i').').', $url);
        }

        $problemi = [];
        if ($esito->senzaEmail > 0) {
            $problemi[] = "{$esito->senzaEmail} senza email";
        }
        if ($esito->usernameOccupati > 0) {
            $problemi[] = "{$esito->usernameOccupati} con nome utente già usato";
        }
        if ($esito->errori > 0) {
            $problemi[] = "{$esito->errori} errori";
        }
        if ($problemi !== []) {
            return new Riquadro('sync', $titolo, Riquadro::ATTENZIONE, 'Ultimo giro completato, ma: '.implode(', ', $problemi).'.', $url);
        }

        return new Riquadro('sync', $titolo, Riquadro::OK, 'Ultimo giro: '.$esito->quando->timezone('Europe/Rome')->format('d/m/Y H:i').'.', $url);
    }

    private function backup(): Riquadro
    {
        $url = route('admin.backup.index');

        return $this->backup->inAllarme(CarbonImmutable::now())
            ? new Riquadro('backup', 'Copie di sicurezza', Riquadro::PROBLEMA, "Backup non aggiornato: nessun backup riuscito nelle ultime ".config('backup.ore_allarme').' ore.', $url)
            : new Riquadro('backup', 'Copie di sicurezza', Riquadro::OK, 'Le copie automatiche sono regolari.', $url);
    }

    private function spidMostrato(): bool
    {
        return $this->oidc->issuer() !== '' || $this->oidc->clientId() !== '';
    }

    private function spid(): ?Riquadro
    {
        if (! $this->spidMostrato()) {
            return null;
        }
        $problemi = $this->oidc->problemi();
        $url = route('admin.integrazioni');

        return $problemi === []
            ? new Riquadro('spid', 'Accesso SPID/CIE', Riquadro::OK, 'Configurato.', $url)
            : new Riquadro('spid', 'Accesso SPID/CIE', Riquadro::PROBLEMA, implode(' ', $problemi), $url);
    }

    private function email(): Riquadro
    {
        $url = route('admin.aspetto');

        $mittente = trim((string) Impostazione::get('mail_from_address'));
        if ($mittente === '') {
            return new Riquadro('email', 'Email in uscita', Riquadro::PROBLEMA, "Manca l'indirizzo del mittente: le email non partono.", $url);
        }
        // Indirizzo d'esempio (quello di partenza o un dominio "example"): le email partono ma da un mittente finto.
        $dominio = mb_strtolower((string) substr(strrchr($mittente, '@') ?: '', 1));
        if (preg_match('/(^|\.)example(\.|$)/', $dominio) === 1) {
            return new Riquadro('email', 'Email in uscita', Riquadro::ATTENZIONE, 'Indirizzo mittente da impostare: quello attuale è un indirizzo di esempio.', $url);
        }

        return new Riquadro('email', 'Email in uscita', Riquadro::OK, 'Indirizzo del mittente impostato.', $url);
    }

    private function webhook(): ?Riquadro
    {
        if (! filled(Impostazione::get('webhook_cittadini_url'))) {
            return null;
        }

        return new Riquadro('webhook', 'Avvisi al sito del comune', Riquadro::NON_VERIFICABILE, 'Nessuna prova automatica: invierebbe un evento al sito.', route('admin.integrazioni'));
    }

    private function miur(): Riquadro
    {
        $url = route('admin.anagrafe-miur.index');
        $quando = $this->miur->stato()['scaricato_at'] ?? null;
        $data = $quando ? CarbonImmutable::parse($quando) : null;

        if ($data === null) {
            return new Riquadro('miur', 'Elenco scuole del Ministero', Riquadro::ATTENZIONE, 'Non è mai stato scaricato.', $url);
        }
        if ($data->lt(now()->subDays(self::GIORNI_MIUR_VECCHIO))) {
            return new Riquadro('miur', 'Elenco scuole del Ministero', Riquadro::ATTENZIONE, 'Scaricato il '.$data->format('d/m/Y').': è passato più di un anno.', $url);
        }

        return new Riquadro('miur', 'Elenco scuole del Ministero', Riquadro::OK, 'Scaricato il '.$data->format('d/m/Y').'.', $url);
    }

    private function orfane(): Riquadro
    {
        $url = route('organizzazione.tipologie-gestori.index');
        $nomi = $this->copertura->orfane()->pluck('descrizione')->all();

        return $nomi === []
            ? new Riquadro('orfane', 'Tipologie senza gestore', Riquadro::OK, 'Ogni tipologia ha almeno un gestore.', $url)
            : new Riquadro('orfane', 'Tipologie senza gestore', Riquadro::ATTENZIONE, 'Le segnalazioni di queste tipologie vanno solo ai supervisori: '.implode(', ', $nomi).'.', $url);
    }

    /** Sedi senza posizione: niente mappa né "Naviga" per chi va sul posto. Nessuna chiamata di rete. */
    private function sedi(): Riquadro
    {
        $url = route('admin.enti.index');
        $titolo = 'Posizione delle sedi';
        $n = \App\Models\Plesso::whereHas('istituto', fn ($q) => $q->where('attivo', true))
            ->where(fn ($q) => $q->whereNull('latitudine')->orWhereNull('longitudine'))->count();
        $esito = json_decode((string) Impostazione::get(\App\Jobs\GeocodificaSedi::CHIAVE_ESITO, ''), true);
        $ultimo = is_array($esito) && isset($esito['quando'])
            ? ' Ultima ricerca: '.\Illuminate\Support\Carbon::parse($esito['quando'])->timezone('Europe/Rome')->format('d/m/Y H:i')
                .' ('.(int) $esito['trovate'].' trovate, '.(int) $esito['non_trovate'].' non trovate, '.(int) $esito['errori'].' errori).'
            : '';

        if ($n === 0) {
            return new Riquadro('sedi', $titolo, Riquadro::OK, 'Tutte le sedi hanno la posizione.'.$ultimo, $url);
        }

        return new Riquadro('sedi', $titolo, Riquadro::ATTENZIONE,
            ($n === 1 ? '1 sede senza posizione' : "$n sedi senza posizione").': usa "Trova le posizioni mancanti" in Enti e sedi.'.$ultimo, $url);
    }

    private function deleghe(): Riquadro
    {
        $n = Delega::where('stato', Delega::RICHIESTA)->distinct()->count('gruppo_richiesta');
        $url = route('admin.deleghe.index', ['stato' => Delega::RICHIESTA]);

        return $n === 0
            ? new Riquadro('deleghe', 'Richieste di delega', Riquadro::OK, 'Nessuna richiesta in attesa.', $url)
            : new Riquadro('deleghe', 'Richieste di delega', Riquadro::ATTENZIONE, $n === 1 ? '1 richiesta in attesa di risposta.' : "$n richieste in attesa di risposta.", $url);
    }

    /** Integrazione con servizio esterno: mostrata solo se accesa, stato dall'ultima prova in cache. */
    private function conProva(string $slug, string $titolo, bool $accesa): ?Riquadro
    {
        if (! $accesa) {
            return null;
        }
        $url = route('admin.integrazioni');
        $esito = Cache::get("integrazioni:prova:$slug");

        if (! is_array($esito) || ! array_key_exists('ok', $esito)) {
            return new Riquadro($slug, $titolo, Riquadro::NON_VERIFICABILE, 'Verifica dalla pagina Integrazioni.', $url);
        }

        return new Riquadro($slug, $titolo, $esito['ok'] ? Riquadro::OK : Riquadro::PROBLEMA, (string) ($esito['messaggio'] ?? ''), $url);
    }
}
