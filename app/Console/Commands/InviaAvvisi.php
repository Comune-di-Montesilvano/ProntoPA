<?php

namespace App\Console\Commands;

use App\Jobs\InviaRiepilogo;
use App\Models\AvvisoInAttesa;
use App\Models\Impostazione;
use App\Models\Impresa;
use App\Models\User;
use App\Services\Sla\CalendarioUfficio;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Giro del riepilogo degli avvisi. `ora`: ogni ora piena in orario d'ufficio e alla chiusura;
 * `giorno`: al mattino dei giorni lavorativi, con gli avvisi di entrambi i ritmi e il quadro di oggi.
 * Per destinatario prenota le righe (`giro`) e accoda un job: un giro concorrente non le riprende.
 */
class InviaAvvisi extends Command
{
    protected $signature = 'avvisi:invia {--ritmo=ora : ora | giorno} {--forza : salta il controllo dell\'orario d\'ufficio}';

    protected $description = 'Invia il riepilogo degli avvisi in attesa (orario o del mattino)';

    private const GIORNI_CONSERVAZIONE = 30;

    /** Prenotazione di un job mai concluso (worker ucciso): oltre, le righe tornano libere. */
    private const ORE_PRENOTAZIONE = 3;

    public function handle(): int
    {
        $ritmo = (string) $this->option('ritmo');
        if (! in_array($ritmo, ['ora', 'giorno'], true)) {
            $this->error('Ritmo non valido: usa ora o giorno.');

            return self::INVALID;
        }

        if (! $this->option('forza') && ! $this->momentoGiusto($ritmo)) {
            $this->info('Fuori orario: nessun invio.');
            $this->pulisci();

            return self::SUCCESS;
        }

        $mattino = $ritmo === 'giorno';
        $accodati = 0;

        $righe = AvvisoInAttesa::query()->inAttesa()->whereNull('giro')
            ->whereIn('ritmo', $mattino ? ['ora', 'giorno'] : ['ora'])
            ->get(['id', 'destinatario_tipo', 'id_utente', 'email']);

        $chiavi = [];
        foreach ($righe->groupBy(fn (AvvisoInAttesa $r) => $r->chiaveDestinatario()) as $chiave => $gruppo) {
            $chiave = (string) $chiave;
            $chiavi[$chiave] = true;
            $giro = (string) Str::uuid();
            $prenotate = AvvisoInAttesa::query()->whereIn('id', $gruppo->pluck('id')->all())
                ->whereNull('giro')->inAttesa()
                ->update(['giro' => $giro]);
            if ($prenotate === 0) {
                continue; // già prese da un giro concorrente
            }
            InviaRiepilogo::dispatch($giro, $chiave, $mattino && $this->primoQuadroDiOggi($chiave));
            $accodati++;
        }

        // Il quadro del mattino arriva anche a chi non ha avvisi in attesa (il job salta se è vuoto).
        if ($mattino && Impostazione::get('digest_enabled', false)) {
            foreach ($this->destinatariQuadro() as $chiave) {
                if (isset($chiavi[$chiave]) || ! $this->primoQuadroDiOggi($chiave)) {
                    continue;
                }
                InviaRiepilogo::dispatch((string) Str::uuid(), $chiave, true);
                $accodati++;
            }
        }

        $this->pulisci();
        $this->info("Riepiloghi accodati: $accodati.");

        return self::SUCCESS;
    }

    private function momentoGiusto(string $ritmo): bool
    {
        $calendario = CalendarioUfficio::daImpostazioni();
        $adesso = now()->setTimezone((string) config('app.timezone'))->startOfMinute();
        $fascia = $calendario->fascia($adesso);
        if ($fascia === null) {
            return false;
        }
        if ($ritmo === 'giorno') {
            return true;
        }

        [$apertura, $chiusura] = $fascia;
        $allOraPiena = $adesso->minute === 0 && $adesso->betweenIncluded($apertura, $chiusura);

        return $allOraPiena || $adesso->equalTo(self::aiQuartiDOra($chiusura));
    }

    /** Chiusura portata al quarto d'ora successivo (lo schedule gira ogni 15 minuti). */
    private static function aiQuartiDOra(Carbon $chiusura): Carbon
    {
        $c = $chiusura->copy()->startOfMinute();
        $resto = $c->minute % 15;

        return $resto === 0 ? $c : $c->addMinutes(15 - $resto);
    }

    /** Una sola volta al giorno per destinatario, anche se il giro del mattino viene rilanciato. */
    private function primoQuadroDiOggi(string $chiave): bool
    {
        return Cache::add('avvisi:quadro:'.now()->format('Y-m-d').':'.$chiave, true, now()->addDay());
    }

    /**
     * Chi può avere un quadro: gestori, operai e persone delle ditte attivi, più le email aziendali
     * delle ditte attive che non coincidono con quella di una loro persona (avrebbe il quadro doppio).
     *
     * @return list<string>
     */
    private function destinatariQuadro(): array
    {
        $utenti = User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['gestore', 'operaio', 'impresa']))
            ->with('roles')
            ->get()
            ->filter(fn (User $u) => $u->attivo !== false)
            ->values();

        $chiavi = $utenti->map(fn (User $u) => 'u:'.$u->id)->all();

        $emailPersone = [];
        foreach ($utenti as $u) {
            if ($u->id_impresa && $u->hasRole('impresa')) {
                $emailPersone[(int) $u->id_impresa][] = mb_strtolower(trim((string) $u->email));
            }
        }

        foreach (Impresa::query()->where('attiva', true)->get(['id_impresa', 'email']) as $impresa) {
            $email = mb_strtolower(trim((string) $impresa->email));
            if ($email === '' || in_array($email, $emailPersone[(int) $impresa->id_impresa] ?? [], true)) {
                continue;
            }
            $chiavi[] = 'e:'.$email;
        }

        return array_values(array_unique($chiavi));
    }

    private function pulisci(): void
    {
        $limite = now()->subDays(self::GIORNI_CONSERVAZIONE);
        AvvisoInAttesa::query()
            ->where(fn ($q) => $q->where('inviata_at', '<', $limite)->orWhere('scartata_at', '<', $limite))
            ->delete();

        AvvisoInAttesa::query()->inAttesa()->whereNotNull('giro')
            ->where('updated_at', '<', now()->subHours(self::ORE_PRENOTAZIONE))
            ->update(['giro' => null]);
    }
}
