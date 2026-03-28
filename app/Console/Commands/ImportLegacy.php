<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ImportLegacy extends Command
{
    protected $signature = 'app:import-legacy
                            {--truncate : Svuota le tabelle di destinazione prima di importare}
                            {--skip-reference : Salta le tabelle di riferimento (istituti, plessi, profili, appalti, imprese)}';

    protected $description = 'Importa i dati dal database legacy nel nuovo schema';

    private int $importedUsers   = 0;
    private int $importedSeg     = 0;
    private int $importedNote    = 0;
    private int $importedStati   = 0;
    private int $importedImprese = 0;

    public function handle(): int
    {
        $this->warn('=== Import dati legacy ===');

        if ($this->option('truncate')) {
            if (! $this->confirm('Svuoto tutte le tabelle di destinazione? Questa operazione è irreversibile.')) {
                $this->info('Operazione annullata.');
                return 0;
            }
            $this->truncateTables();
        }

        if (! $this->option('skip-reference')) {
            $this->importIstituti();
            $this->importPlessi();
            $this->importProfili();
            $this->importImprese();
            $this->importAppalti();
        }

        $this->importUtenti();
        $this->importSegnalazioni();
        $this->importNote();
        $this->importStati();

        $this->newLine();
        $this->info('=== Import completato ===');
        $this->table(
            ['Tabella', 'Righe importate'],
            [
                ['Utenti',       $this->importedUsers],
                ['Segnalazioni', $this->importedSeg],
                ['Note',         $this->importedNote],
                ['Storico stati', $this->importedStati],
                ['Imprese',      $this->importedImprese],
            ]
        );

        return 0;
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    private function truncateTables(): void
    {
        $this->info('Svuotamento tabelle...');
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('stati_segnalazioni')->truncate();
        DB::table('note_segnalazioni')->truncate();
        DB::table('segnalazioni')->truncate();
        DB::table('model_has_roles')->truncate();
        DB::table('users')->truncate();
        DB::table('appalti')->truncate();
        DB::table('imprese')->truncate();
        DB::table('plessi')->truncate();
        DB::table('istituti')->truncate();
        DB::table('profili')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $this->line('  Fatto.');
    }

    private function legacy(): \Illuminate\Database\Connection
    {
        return DB::connection('legacy');
    }

    // ─── Reference tables ────────────────────────────────────────────────────────

    private function importIstituti(): void
    {
        $this->info('Importazione istituti...');
        $rows = $this->legacy()->table('istituti')->get();
        foreach ($rows as $r) {
            DB::table('istituti')->updateOrInsert(
                ['id_istituto' => $r->id_istituto],
                [
                    'descrizione'           => $r->descrizione,
                    'codice_meccanografico' => $r->codice_meccanografico,
                    'dirigente'             => $r->dirigente,
                    'email'                 => $r->email,
                    'recapiti'              => $r->recapiti,
                ]
            );
        }
        $this->line("  {$rows->count()} istituti.");
    }

    private function importPlessi(): void
    {
        $this->info('Importazione plessi...');
        $rows = $this->legacy()->table('plessi')->get();
        foreach ($rows as $r) {
            DB::table('plessi')->updateOrInsert(
                ['id_plesso' => $r->id_plesso],
                [
                    'id_istituto'           => $r->id_istituto,
                    'nome'                  => $r->nome,
                    'codice_meccanografico' => $r->codice_meccanografico,
                    'indirizzo'             => $r->indirizzo,
                    'referente'             => $r->referente,
                    'email'                 => $r->email,
                    'recapiti'              => $r->recapiti,
                ]
            );
        }
        $this->line("  {$rows->count()} plessi.");
    }

    private function importProfili(): void
    {
        $this->info('Importazione profili...');
        $rows = $this->legacy()->table('profili')->get();
        foreach ($rows as $r) {
            DB::table('profili')->updateOrInsert(
                ['id_profilo' => $r->id_profilo],
                [
                    'descrizione'    => $r->descrizione,
                    'limita_istituto' => (bool) $r->limita_istituto,
                    'id_istituto'    => $r->id_istituto ?: null,
                ]
            );
        }
        $this->line("  {$rows->count()} profili.");
    }

    private function importAppalti(): void
    {
        $this->info('Importazione appalti...');
        $rows = $this->legacy()->table('appalti')->get();
        foreach ($rows as $r) {
            DB::table('appalti')->updateOrInsert(
                ['id_appalto' => $r->id_appalto],
                [
                    'id_gruppo'       => $r->id_gruppo,
                    'descrizione'     => $r->descrizione,
                    'id_impresa'      => is_numeric($r->id_impresa) ? (int) $r->id_impresa : null,
                    'CIG'             => $r->CIG,
                    'importo_appalto' => $r->importo_appalto,
                    'valido'          => (bool) ($r->valido ?? 1),
                ]
            );
        }
        $this->line("  {$rows->count()} appalti.");
    }

    private function importImprese(): void
    {
        $this->info('Importazione imprese...');
        $rows = $this->legacy()->table('imprese')->get();
        foreach ($rows as $r) {
            DB::table('imprese')->updateOrInsert(
                ['id_impresa' => $r->id_impresa],
                [
                    'ragione_sociale' => $r->ragione_sociale,
                    'partita_iva'     => $r->partita_iva,
                    'referente'       => $r->referente,
                    'email'           => $r->email,
                    'cellulare'       => $r->cellulare,
                    'note'            => $r->note,
                ]
            );
            $this->importedImprese++;
        }
        $this->line("  {$this->importedImprese} imprese.");
    }

    // ─── Users ───────────────────────────────────────────────────────────────────

    private function importUtenti(): void
    {
        $this->info('Importazione utenti...');

        // Get Spatie role IDs
        $roles = DB::table('roles')->pluck('id', 'name');

        $rows = $this->legacy()->table('utenti')->get();
        $bar  = $this->output->createProgressBar($rows->count());
        $bar->start();

        $usedEmails = DB::table('users')->pluck('email')->flip()->toArray();

        foreach ($rows as $u) {
            // Determine role
            $ruolo = $this->determineRole($u);

            // Generate unique email: prefer legacy email, fallback to username@legacy.local
            // If already taken (duplicate legacy emails), append username suffix
            $baseEmail = $u->email ?: ($u->username . '@legacy.local');
            if (isset($usedEmails[$baseEmail])) {
                $baseEmail = $u->username . '@legacy.local';
            }
            // Still duplicate? Append numeric suffix
            $email  = $baseEmail;
            $suffix = 1;
            while (isset($usedEmails[$email])) {
                $email = $u->username . $suffix . '@legacy.local';
                $suffix++;
            }
            $usedEmails[$email] = true;

            $userId = DB::table('users')->insertGetId([
                'name'                      => $u->nome_esteso ?? $u->username,
                'username'                  => $u->username,
                'email'                     => $email,
                'password'                  => Hash::make(str()->random(20)), // unusable password
                'password_legacy'           => $u->pwd,
                'id_profilo'                => $u->id_profilo ?: null,
                'id_provenienza'            => $u->id_provenienza ?: null,
                'amministratore'            => (bool) $u->amministratore,
                'gestore_segnalazioni'      => (bool) $u->gestore_segnalazioni,
                'supervisore_segnalazioni'  => (bool) $u->supervisore_segnalazioni,
                'last_login'                => $u->last_login,
                'email_verified_at'         => now(),
                'created_at'                => now(),
                'updated_at'                => now(),
            ]);

            // Assign Spatie role
            if (isset($roles[$ruolo])) {
                DB::table('model_has_roles')->insertOrIgnore([
                    'role_id'    => $roles[$ruolo],
                    'model_type' => 'App\\Models\\User',
                    'model_id'   => $userId,
                ]);
            }

            $this->importedUsers++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->line("  {$this->importedUsers} utenti importati.");
    }

    private function determineRole(object $u): string
    {
        if ($u->amministratore) {
            return 'admin';
        }
        if ($u->gestore_segnalazioni) {
            return 'gestore';
        }
        // Check if the profilo is a school (limita_istituto)
        // We can't easily cross-reference here, so mark as segnalatore if has id_profilo
        return 'segnalatore';
    }

    // ─── Segnalazioni ────────────────────────────────────────────────────────────

    private function importSegnalazioni(): void
    {
        $this->info('Importazione segnalazioni...');

        // Map legacy user IDs to new user IDs via username/order of insert
        // Since we insert sequentially the IDs differ — build a map
        $userMap = $this->buildUserIdMap();

        $rows = $this->legacy()->table('segnalazioni')->get();
        $bar  = $this->output->createProgressBar($rows->count());
        $bar->start();

        foreach ($rows as $s) {
            // Map zero values to null
            $idPlesso   = $s->id_plesso   > 0 ? $s->id_plesso   : null;
            $idAppalto  = $s->id_appalto  > 0 ? $s->id_appalto  : null;
            $idOperatore = $s->id_operatore_assegnato > 0
                ? ($userMap[$s->id_operatore_assegnato] ?? null)
                : null;
            $idUtenteSeg = $s->id_utente_segnalazione > 0
                ? ($userMap[$s->id_utente_segnalazione] ?? null)
                : null;

            // data_chiusura: legacy stores 0000-00-00 for open segnalazioni
            $dataChiusura = ($s->data_chiusura && $s->data_chiusura !== '0000-00-00 00:00:00')
                ? $s->data_chiusura
                : null;

            DB::table('segnalazioni')->insert([
                'id_segnalazione'           => $s->id_segnalazione,
                'data_segnalazione'         => $s->data_segnalazione,
                'data_chiusura'             => $dataChiusura,
                'id_tipologia_segnalazione' => $s->id_tipologia_segnalazione,
                'id_plesso'                 => $idPlesso ?? 0,
                'id_utente_segnalazione'    => $idUtenteSeg ?? 0,
                'latitudine'                => $s->latitudine,
                'longitudine'               => $s->longitudine,
                'testo_segnalazione'        => $s->testo_segnalazione,
                'id_stato_segnalazione'     => $s->id_stato_segnalazione,
                'id_provenienza'            => $s->id_provenienza,
                'segnalante'                => $s->segnalante,
                'email'                     => $s->email,
                'telefono'                  => $s->telefono,
                'id_appalto'                => $idAppalto,
                'id_operatore_assegnato'    => $idOperatore ?? 0,
                'importo_preventivo'        => $s->importo_preventivo,
                'importo_liquidato'         => $s->importo_liquidato,
                'flag_evidenza'             => (bool) $s->flag_evidenza,
            ]);

            $this->importedSeg++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->line("  {$this->importedSeg} segnalazioni importate.");
    }

    // ─── Note ────────────────────────────────────────────────────────────────────

    private function importNote(): void
    {
        $this->info('Importazione note...');

        $userMap = $this->buildUserIdMap();

        $rows = $this->legacy()->table('note_segnalazioni')->get();
        $bar  = $this->output->createProgressBar($rows->count());
        $bar->start();

        foreach ($rows as $n) {
            $idUtente = $n->id_utente > 0 ? ($userMap[$n->id_utente] ?? null) : null;

            DB::table('note_segnalazioni')->insert([
                'id_nota'          => $n->id_nota,
                'id_segnalazione'  => $n->id_segnalazione,
                'data'             => $n->data,
                'testo'            => $n->testo,
                'id_utente'        => $idUtente ?? 0,
                'visibile_web'     => (bool) $n->visibile_web,
                'visibile_impresa' => (bool) $n->visibile_impresa,
            ]);

            $this->importedNote++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->line("  {$this->importedNote} note importate.");
    }

    // ─── Storico stati ───────────────────────────────────────────────────────────

    private function importStati(): void
    {
        $this->info('Importazione storico stati...');

        $userMap = $this->buildUserIdMap();

        $rows = $this->legacy()->table('stati_segnalazioni')->get();
        $bar  = $this->output->createProgressBar($rows->count());
        $bar->start();

        foreach ($rows as $st) {
            $idUtente    = $st->id_utente > 0 ? ($userMap[$st->id_utente] ?? null) : null;
            $idCollegato = $st->id_utente_collegato > 0 ? ($userMap[$st->id_utente_collegato] ?? null) : null;
            $idAppalto   = $st->id_appalto > 0 ? $st->id_appalto : null;

            DB::table('stati_segnalazioni')->insert([
                'id_segnalazione'       => $st->id_segnalazione,
                'data_registrazione'    => $st->data_registrazione,
                'id_stato_segnalazione' => $st->id_stato_segnalazione,
                'id_utente'             => $idUtente ?? 0,
                'id_utente_collegato'   => $idCollegato ?? 0,
                'id_appalto'            => $idAppalto ?? 0,
            ]);

            $this->importedStati++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->line("  {$this->importedStati} stati importati.");
    }

    // ─── User ID map ─────────────────────────────────────────────────────────────

    private ?array $userIdMapCache = null;

    /**
     * Build a map of legacy id_utente → new users.id
     * We match by username since the legacy IDs differ from new auto-increment IDs.
     */
    private function buildUserIdMap(): array
    {
        if ($this->userIdMapCache !== null) {
            return $this->userIdMapCache;
        }

        $legacyUsers = $this->legacy()->table('utenti')
            ->select('id_utente', 'username')
            ->get()
            ->keyBy('id_utente');

        $newUsers = DB::table('users')
            ->select('id', 'username')
            ->get()
            ->keyBy('username');

        $map = [];
        foreach ($legacyUsers as $legacyId => $u) {
            if (isset($newUsers[$u->username])) {
                $map[$legacyId] = $newUsers[$u->username]->id;
            }
        }

        $this->userIdMapCache = $map;
        return $map;
    }
}
