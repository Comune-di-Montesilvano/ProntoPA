<?php

namespace App\Console\Commands;

use App\Enums\SegnalazioneStato;
use App\Models\AdesioneSegnalazione;
use App\Models\Impresa;
use App\Models\Istituto;
use App\Models\Plesso;
use App\Models\Provenienza;
use App\Models\Segnalazione;
use App\Models\Squadra;
use App\Models\StoricoStatoSegnalazione;
use App\Models\TipologiaSegnalazione;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Popola l'istanza con dati realistici (istituti, utenti, imprese,
 * segnalazioni in vari stati) per chi valuta il riuso del software.
 *
 * Tutti i record creati sono taggati (email @demo.prontopa.it, istituti
 * con fonte_dati=demo) così il comando è rilanciabile: ripulisce la sua
 * demo precedente prima di ricrearla, senza toccare dati reali.
 */
class PopulateDemoData extends Command
{
    protected $signature = 'demo {--force : Consenti l\'esecuzione anche in ambiente production}';

    protected $description = 'Popola il database con dati demo realistici per valutare il riuso';

    private const EMAIL_DOMAIN = '@demo.prontopa.it';

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Ambiente production: rilancia con --force se sei sicuro di voler inserire dati demo.');

            return self::FAILURE;
        }

        if (TipologiaSegnalazione::count() === 0 || Provenienza::count() === 0) {
            $this->error('Tabelle di riferimento vuote. Esegui prima "php artisan migrate --seed".');

            return self::FAILURE;
        }

        $this->pulisciDemoPrecedente();

        DB::transaction(function () {
            [$istituti, $plessi] = $this->creaIstitutiEPlessi();
            $imprese              = $this->creaImprese();
            $utenti                = $this->creaUtenti($istituti, $imprese);
            $this->creaSquadra($utenti);
            $segnalazioni          = $this->creaSegnalazioni($plessi, $utenti);
            $this->creaAdesioniDuplicati($segnalazioni, $utenti);
        });

        $this->newLine();
        $this->info('Dati demo creati.');
        $this->line('Accedi con uno di questi utenti (password: demo1234):');
        $this->table(['Ruolo', 'Email'], [
            ['Gestore supervisore', 'supervisore' . self::EMAIL_DOMAIN],
            ['Gestore', 'gestore' . self::EMAIL_DOMAIN],
            ['Operaio', 'operaio1' . self::EMAIL_DOMAIN],
            ['Segnalatore (scuola)', 'segnalatore1' . self::EMAIL_DOMAIN],
            ['Segnalatore (URP)', 'segnalatore-urp' . self::EMAIL_DOMAIN],
            ['Impresa', 'impresa1' . self::EMAIL_DOMAIN],
        ]);

        return self::SUCCESS;
    }

    private function pulisciDemoPrecedente(): void
    {
        $istitutiIds = Istituto::where('fonte_dati', 'demo')->pluck('id_istituto');
        $plessiIds   = Plesso::whereIn('id_istituto', $istitutiIds)->pluck('id_plesso');
        $segIds      = Segnalazione::whereIn('id_plesso', $plessiIds)->pluck('id_segnalazione');

        StoricoStatoSegnalazione::whereIn('id_segnalazione', $segIds)->delete();
        AdesioneSegnalazione::whereIn('id_segnalazione', $segIds)->delete();
        Segnalazione::whereIn('id_segnalazione', $segIds)->delete();

        Plesso::whereIn('id_plesso', $plessiIds)->delete();
        Istituto::whereIn('id_istituto', $istitutiIds)->delete();

        Squadra::where('nome', 'like', 'Squadra demo%')->delete();
        User::where('email', 'like', '%' . self::EMAIL_DOMAIN)->delete();
        Impresa::where('note', 'DEMO')->delete();
    }

    /** @return array{0: \Illuminate\Support\Collection, 1: \Illuminate\Support\Collection} */
    private function creaIstitutiEPlessi(): array
    {
        $nomi = [
            'IC Montesilvano Nord',
            'IC Montesilvano Sud',
            'Liceo Scientifico G. Marconi',
            'Comune di Montesilvano — sede centrale',
        ];

        $istituti = collect($nomi)->map(fn (string $nome) => Istituto::create([
            'descrizione' => $nome,
            'tipo'        => str_starts_with($nome, 'Comune') ? 'ente' : 'scuola',
            'attivo'      => true,
            'fonte_dati'  => 'demo',
        ]));

        $plessi = $istituti->flatMap(function (Istituto $istituto, int $i) {
            return collect(range(1, 2))->map(fn (int $n) => Plesso::create([
                'id_istituto' => $istituto->id_istituto,
                'nome'        => $istituto->descrizione . ' — plesso ' . $n,
                'indirizzo'   => fake('it_IT')->streetAddress(),
            ]));
        });

        return [$istituti, $plessi];
    }

    /** @return \Illuminate\Support\Collection<int, Impresa> */
    private function creaImprese()
    {
        return collect([
            'Edil Service Srl',
            'Termoidraulica D\'Angelo',
        ])->map(fn (string $nome) => Impresa::create([
            'ragione_sociale' => $nome,
            'partita_iva'     => fake()->numerify('###########'),
            'referente'       => fake('it_IT')->name(),
            'email'           => Str::slug($nome) . self::EMAIL_DOMAIN,
            'cellulare'       => fake('it_IT')->phoneNumber(),
            'note'            => 'DEMO',
        ]));
    }

    /** @return array<string, User> */
    private function creaUtenti($istituti, $imprese): array
    {
        $password = Hash::make('demo1234');
        $urp      = Provenienza::where('descrizione', 'URP')->value('id_provenienza');
        $scuole   = Provenienza::where('descrizione', 'DIREZIONI DIDATTICHE')->value('id_provenienza');

        $u = [];

        $u['supervisore'] = User::create([
            'name' => 'Anna Verdi', 'username' => 'supervisore', 'email' => 'supervisore' . self::EMAIL_DOMAIN,
            'password' => $password, 'attivo' => true, 'email_verified_at' => now(),
            'gestore_segnalazioni' => true, 'supervisore_segnalazioni' => true,
        ]);
        $u['gestore'] = User::create([
            'name' => 'Marco Bianchi', 'username' => 'gestore', 'email' => 'gestore' . self::EMAIL_DOMAIN,
            'password' => $password, 'attivo' => true, 'email_verified_at' => now(),
            'gestore_segnalazioni' => true,
        ]);
        $u['operaio1'] = User::create([
            'name' => 'Luigi Rossi', 'username' => 'operaio1', 'email' => 'operaio1' . self::EMAIL_DOMAIN,
            'password' => $password, 'attivo' => true, 'email_verified_at' => now(),
        ]);
        $u['operaio2'] = User::create([
            'name' => 'Paolo Neri', 'username' => 'operaio2', 'email' => 'operaio2' . self::EMAIL_DOMAIN,
            'password' => $password, 'attivo' => true, 'email_verified_at' => now(),
        ]);
        $u['segnalatore1'] = User::create([
            'name' => 'Giulia Ferri', 'username' => 'segnalatore1', 'email' => 'segnalatore1' . self::EMAIL_DOMAIN,
            'password' => $password, 'attivo' => true, 'email_verified_at' => now(),
            'id_istituto' => $istituti[0]->id_istituto, 'id_provenienza' => $scuole,
        ]);
        $u['segnalatore2'] = User::create([
            'name' => 'Sara Colombo', 'username' => 'segnalatore2', 'email' => 'segnalatore2' . self::EMAIL_DOMAIN,
            'password' => $password, 'attivo' => true, 'email_verified_at' => now(),
            'id_istituto' => $istituti[1]->id_istituto, 'id_provenienza' => $scuole,
        ]);
        $u['segnalatore_urp'] = User::create([
            'name' => 'Franco Galli', 'username' => 'segnalatore-urp', 'email' => 'segnalatore-urp' . self::EMAIL_DOMAIN,
            'password' => $password, 'attivo' => true, 'email_verified_at' => now(),
            'id_provenienza' => $urp,
        ]);
        $u['impresa1'] = User::create([
            'name' => 'Edil Service — referente', 'username' => 'impresa1', 'email' => 'impresa1' . self::EMAIL_DOMAIN,
            'password' => $password, 'attivo' => true, 'email_verified_at' => now(),
            'id_impresa' => $imprese[0]->id_impresa,
        ]);
        $u['impresa2'] = User::create([
            'name' => 'Termoidraulica — referente', 'username' => 'impresa2', 'email' => 'impresa2' . self::EMAIL_DOMAIN,
            'password' => $password, 'attivo' => true, 'email_verified_at' => now(),
            'id_impresa' => $imprese[1]->id_impresa,
        ]);

        $u['supervisore']->syncRoles(['gestore']);
        $u['gestore']->syncRoles(['gestore']);
        $u['operaio1']->syncRoles(['operaio']);
        $u['operaio2']->syncRoles(['operaio']);
        $u['segnalatore1']->syncRoles(['segnalatore']);
        $u['segnalatore2']->syncRoles(['segnalatore']);
        $u['segnalatore_urp']->syncRoles(['segnalatore']);
        $u['impresa1']->syncRoles(['impresa']);
        $u['impresa2']->syncRoles(['impresa']);

        return $u;
    }

    private function creaSquadra(array $utenti): void
    {
        $squadra = Squadra::create([
            'nome'           => 'Squadra demo — manutentori',
            'id_caposquadra' => $utenti['operaio1']->id,
            'attiva'         => true,
        ]);
        $squadra->membri()->sync([$utenti['operaio1']->id, $utenti['operaio2']->id]);
    }

    /** @return \Illuminate\Support\Collection<int, Segnalazione> */
    private function creaSegnalazioni($plessi, array $utenti)
    {
        $tipologieIds = TipologiaSegnalazione::pluck('id_tipologia_segnalazione');
        $urp          = Provenienza::where('descrizione', 'URP')->value('id_provenienza');
        $scuole       = Provenienza::where('descrizione', 'DIREZIONI DIDATTICHE')->value('id_provenienza');

        // Distribuzione realistica sui 10 stati: più nuove/in lavorazione
        // che chiuse, qualche annullata/archiviata.
        $distribuzione = [
            SegnalazioneStato::NUOVA->value                => 8,
            SegnalazioneStato::IN_CARICO->value             => 6,
            SegnalazioneStato::ASSEGNATA_OPERATORE->value   => 5,
            SegnalazioneStato::ASSEGNATA_IMPRESA->value     => 5,
            SegnalazioneStato::PREVENTIVO_IN_ATTESA->value  => 3,
            SegnalazioneStato::SOSPESA->value               => 2,
            SegnalazioneStato::COMPLETATA->value            => 15,
            SegnalazioneStato::DUPLICATA->value             => 2,
            SegnalazioneStato::ANNULLATA->value             => 2,
            SegnalazioneStato::ARCHIVIATA->value            => 5,
        ];

        $testi = [
            'Perdita d\'acqua nel bagno del piano terra',
            'Infisso della finestra non si chiude bene',
            'Lampada del corridoio bruciata',
            'Termosifone non scalda in aula 3',
            'Erba alta nel cortile esterno',
            'Intonaco caduto vicino all\'ingresso principale',
            'Porta antipanico bloccata',
            'Presa elettrica non funzionante in segreteria',
            'Tapparella rotta nell\'aula magna',
            'Scarico otturato nei servizi igienici',
        ];

        $segnalazioni = collect();

        foreach ($distribuzione as $statoId => $quantita) {
            for ($i = 0; $i < $quantita; $i++) {
                $stato       = SegnalazioneStato::from($statoId);
                $plesso      = $plessi->random();
                $dataCreata  = now()->subDays(fake()->numberBetween(1, 180))->subHours(fake()->numberBetween(0, 23));
                $daScuola    = fake()->boolean(70);
                $provenienza = $daScuola ? $scuole : $urp;
                $segnalante  = $daScuola ? $utenti['segnalatore1'] : $utenti['segnalatore_urp'];

                $assegnaOperatore = in_array($stato, [
                    SegnalazioneStato::ASSEGNATA_OPERATORE, SegnalazioneStato::COMPLETATA, SegnalazioneStato::ARCHIVIATA,
                ], true) && fake()->boolean(50);

                $assegnaImpresa = in_array($stato, [
                    SegnalazioneStato::ASSEGNATA_IMPRESA, SegnalazioneStato::PREVENTIVO_IN_ATTESA,
                    SegnalazioneStato::COMPLETATA, SegnalazioneStato::ARCHIVIATA,
                ], true);

                $segnalazione = Segnalazione::create([
                    'id_tipologia_segnalazione' => $tipologieIds->random(),
                    'id_plesso'                 => $plesso->id_plesso,
                    'id_utente_segnalazione'    => $segnalante->id,
                    'id_cittadino_segnalazione' => 0,
                    'id_stradario'               => 0,
                    'id_area'                    => 0,
                    'id_immobile'                => 0,
                    'latitudine'                 => 0,
                    'longitudine'                => 0,
                    'zoom'                       => 18,
                    'testo_segnalazione'         => fake()->randomElement($testi),
                    'flag_riservata'             => ! $stato->isTerminale() || fake()->boolean(30),
                    'flag_pubblicata'            => $stato->isTerminale() && fake()->boolean(60),
                    'flag_evidenza'              => false,
                    'id_stato_segnalazione'      => $stato,
                    'id_provenienza'             => $provenienza,
                    'id_operatore_assegnato'     => $assegnaOperatore ? $utenti['operaio' . fake()->numberBetween(1, 2)]->id : 0,
                    'id_squadra_assegnata'       => null,
                    'segnalante'                 => $segnalante->name,
                    'email'                      => $segnalante->email,
                    'telefono'                   => fake('it_IT')->phoneNumber(),
                    'importo_preventivo'         => $assegnaImpresa ? fake()->randomFloat(2, 150, 4500) : 0,
                    'importo_liquidato'          => $stato === SegnalazioneStato::COMPLETATA ? fake()->randomFloat(2, 150, 4500) : 0,
                    'segnalazione_urgente'       => fake()->boolean(15),
                    'livello_priorita'           => fake()->numberBetween(1, 4),
                    'data_chiusura'              => $stato->isTerminale() ? $dataCreata->clone()->addDays(fake()->numberBetween(1, 30)) : null,
                ]);

                // data_segnalazione (= created_at custom) va forzata dopo la
                // create perché il modello non ha timestamps automatici coerenti col fake in passato.
                $segnalazione->forceFill(['data_segnalazione' => $dataCreata])->saveQuietly();

                StoricoStatoSegnalazione::create([
                    'id_segnalazione'        => $segnalazione->id_segnalazione,
                    'id_stato_segnalazione'  => 1,
                    'id_utente'              => $segnalante->id,
                    'data_registrazione'     => $dataCreata,
                ]);

                if ($stato->value !== SegnalazioneStato::NUOVA->value) {
                    StoricoStatoSegnalazione::create([
                        'id_segnalazione'       => $segnalazione->id_segnalazione,
                        'id_stato_segnalazione' => $stato->value,
                        'id_utente'             => $utenti['gestore']->id,
                        'data_registrazione'    => $dataCreata->clone()->addDays(fake()->numberBetween(1, 15)),
                    ]);
                }

                $segnalazioni->push($segnalazione);
            }
        }

        return $segnalazioni;
    }

    private function creaAdesioniDuplicati($segnalazioni, array $utenti): void
    {
        // Prende 2 segnalazioni "nuove" per simulare adesioni a duplicati
        // (stesso problema segnalato da più persone).
        $candidate = $segnalazioni->where('id_stato_segnalazione', SegnalazioneStato::NUOVA)->take(2);

        foreach ($candidate as $segnalazione) {
            AdesioneSegnalazione::create([
                'id_segnalazione' => $segnalazione->id_segnalazione,
                'id_utente'       => $utenti['segnalatore2']->id,
                'segnalante'      => fake('it_IT')->name(),
                'telefono'        => fake('it_IT')->phoneNumber(),
                'email'           => fake()->safeEmail(),
            ]);
        }
    }
}
