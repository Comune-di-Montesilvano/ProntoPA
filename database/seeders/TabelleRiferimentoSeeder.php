<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TabelleRiferimentoSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('provenienze_segnalazioni')->insertOrIgnore([
            ['id_provenienza' => 1, 'descrizione' => 'SEGNALAZIONI INTERNE'],
            ['id_provenienza' => 2, 'descrizione' => 'DIREZIONI DIDATTICHE'],
            ['id_provenienza' => 3, 'descrizione' => 'URP'],
            ['id_provenienza' => 4, 'descrizione' => 'PORTALE'],
        ]);

        DB::table('db_stato_segnalazioni')->insertOrIgnore([
            ['id_stato' => 1,  'descrizione' => 'Nuova',                'iniziale' => 1, 'in_carico' => 0, 'id_gestione' => 0, 'sospesa' => 0, 'chiusura' => 0, 'colore_sfondo' => 'primary'],
            ['id_stato' => 2,  'descrizione' => 'In carico',             'iniziale' => 0, 'in_carico' => 1, 'id_gestione' => 1, 'sospesa' => 0, 'chiusura' => 0, 'colore_sfondo' => 'info'],
            ['id_stato' => 3,  'descrizione' => 'Assegnata a operatore', 'iniziale' => 0, 'in_carico' => 0, 'id_gestione' => 1, 'sospesa' => 0, 'chiusura' => 0, 'colore_sfondo' => 'success'],
            ['id_stato' => 4,  'descrizione' => 'Assegnata a impresa',   'iniziale' => 0, 'in_carico' => 0, 'id_gestione' => 1, 'sospesa' => 0, 'chiusura' => 0, 'colore_sfondo' => 'primary'],
            ['id_stato' => 5,  'descrizione' => 'Preventivo in attesa',  'iniziale' => 0, 'in_carico' => 0, 'id_gestione' => 1, 'sospesa' => 0, 'chiusura' => 0, 'colore_sfondo' => 'warning'],
            ['id_stato' => 6,  'descrizione' => 'Sospesa',               'iniziale' => 0, 'in_carico' => 1, 'id_gestione' => 0, 'sospesa' => 1, 'chiusura' => 0, 'colore_sfondo' => 'secondary'],
            ['id_stato' => 7,  'descrizione' => 'Completata',            'iniziale' => 0, 'in_carico' => 0, 'id_gestione' => 0, 'sospesa' => 0, 'chiusura' => 1, 'colore_sfondo' => 'success'],
            ['id_stato' => 8,  'descrizione' => 'Duplicata',             'iniziale' => 0, 'in_carico' => 0, 'id_gestione' => 0, 'sospesa' => 0, 'chiusura' => 1, 'colore_sfondo' => 'warning'],
            ['id_stato' => 9,  'descrizione' => 'Annullata',             'iniziale' => 0, 'in_carico' => 0, 'id_gestione' => 0, 'sospesa' => 0, 'chiusura' => 1, 'colore_sfondo' => 'danger'],
            ['id_stato' => 10, 'descrizione' => 'Archiviata',            'iniziale' => 0, 'in_carico' => 0, 'id_gestione' => 0, 'sospesa' => 0, 'chiusura' => 1, 'colore_sfondo' => 'secondary'],
        ]);

        DB::table('db_azioni')->insertOrIgnore([
            ['id_azione' => 1,  'codice' => 'prendi_in_carico',   'descrizione' => 'Prendi in carico',     'id_stato_segnalazione' => 2,    'competenza_azione' => 0, 'colore' => 'primary',   'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 0, 'ordine' => 10,  'parametri_filtro' => '{"stati":[1]}'],
            ['id_azione' => 2,  'codice' => 'assegna_operatore',  'descrizione' => 'Assegna operatore',    'id_stato_segnalazione' => 3,    'competenza_azione' => 0, 'colore' => 'primary',   'flag_appalto' => 0, 'flag_operatore' => 1, 'flag_notifica' => 1, 'ordine' => 20,  'parametri_filtro' => '{"stati":[2,6]}'],
            ['id_azione' => 3,  'codice' => 'assegna_impresa',    'descrizione' => 'Assegna impresa',      'id_stato_segnalazione' => 4,    'competenza_azione' => 0, 'colore' => 'secondary', 'flag_appalto' => 1, 'flag_operatore' => 0, 'flag_notifica' => 1, 'ordine' => 30,  'parametri_filtro' => '{"stati":[2,6]}'],
            ['id_azione' => 4,  'codice' => 'sospendi',           'descrizione' => 'Sospendi',             'id_stato_segnalazione' => 6,    'competenza_azione' => 2, 'colore' => 'warning',   'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 0, 'ordine' => 40,  'parametri_filtro' => '{"stati":[1,2,3,4,5]}'],
            ['id_azione' => 5,  'codice' => 'riprendi',           'descrizione' => 'Riprendi',             'id_stato_segnalazione' => null, 'competenza_azione' => 2, 'colore' => 'success',   'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 0, 'ordine' => 50,  'parametri_filtro' => '{"stati":[6]}'],
            ['id_azione' => 6,  'codice' => 'presenta_preventivo','descrizione' => 'Presenta preventivo',  'id_stato_segnalazione' => 5,    'competenza_azione' => 1, 'colore' => 'primary',   'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 1, 'ordine' => 60,  'parametri_filtro' => '{"stati":[4]}'],
            ['id_azione' => 7,  'codice' => 'accetta_preventivo', 'descrizione' => 'Accetta preventivo',   'id_stato_segnalazione' => 4,    'competenza_azione' => 0, 'colore' => 'success',   'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 1, 'ordine' => 70,  'parametri_filtro' => '{"stati":[5]}'],
            ['id_azione' => 8,  'codice' => 'rifiuta_preventivo', 'descrizione' => 'Rifiuta preventivo',   'id_stato_segnalazione' => 4,    'competenza_azione' => 0, 'colore' => 'danger',    'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 1, 'ordine' => 75,  'parametri_filtro' => '{"stati":[5]}'],
            ['id_azione' => 9,  'codice' => 'chiudi',             'descrizione' => 'Chiudi',               'id_stato_segnalazione' => 7,    'competenza_azione' => 0, 'colore' => 'success',   'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 1, 'ordine' => 80,  'parametri_filtro' => '{"stati":[2,3,4,5]}'],
            ['id_azione' => 10, 'codice' => 'segna_duplicata',    'descrizione' => 'Segna come duplicata', 'id_stato_segnalazione' => 8,    'competenza_azione' => 0, 'colore' => 'warning',   'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 0, 'ordine' => 90,  'parametri_filtro' => '{"stati":[1,2,3,4,5,6]}'],
            ['id_azione' => 11, 'codice' => 'collega',            'descrizione' => 'Collega a precedente', 'id_stato_segnalazione' => null, 'competenza_azione' => 0, 'colore' => 'secondary', 'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 0, 'ordine' => 95,  'parametri_filtro' => '{"stati":[1,2,3,4,5,6]}'],
            ['id_azione' => 12, 'codice' => 'annulla',            'descrizione' => 'Annulla',              'id_stato_segnalazione' => 9,    'competenza_azione' => 0, 'colore' => 'danger',    'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 0, 'ordine' => 100, 'parametri_filtro' => '{"stati":[1,2,3,4,5,6]}'],
            ['id_azione' => 13, 'codice' => 'archivia',           'descrizione' => 'Archivia',             'id_stato_segnalazione' => 10,   'competenza_azione' => 0, 'colore' => 'secondary', 'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 0, 'ordine' => 110, 'parametri_filtro' => '{"stati":[1,2,3,4,5,6]}'],
            ['id_azione' => 14, 'codice' => 'riapri',             'descrizione' => 'Riapri',               'id_stato_segnalazione' => 2,    'competenza_azione' => 0, 'colore' => 'warning',   'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 1, 'ordine' => 120, 'parametri_filtro' => '{"stati":[7,8,9,10]}'],
        ]);

        DB::table('gruppi_segnalazioni')->insertOrIgnore([
            ['id_gruppo' => 1, 'descrizione' => 'EDILIZIA SCOLASTICA', 'icona' => 'fas fa-building',      'tipologia' => 0, 'cittadini' => 0],
            ['id_gruppo' => 2, 'descrizione' => "VIABILITA'",          'icona' => 'fas fa-road',           'tipologia' => 3, 'cittadini' => 1],
            ['id_gruppo' => 3, 'descrizione' => 'SEGNALETICA',         'icona' => 'fas fa-traffic-light',  'tipologia' => 3, 'cittadini' => 1],
            ['id_gruppo' => 4, 'descrizione' => 'VERDE PUBBLICO',      'icona' => 'fas fa-seedling',       'tipologia' => 3, 'cittadini' => 1],
            ['id_gruppo' => 5, 'descrizione' => 'PARCHI E GIARDINI',   'icona' => 'fas fa-tree',           'tipologia' => 2, 'cittadini' => 1],
            ['id_gruppo' => 6, 'descrizione' => 'ABBANDONO RIFIUTI',   'icona' => 'fas fa-recycle',        'tipologia' => 3, 'cittadini' => 1],
        ]);

        DB::table('tipologie_segnalazioni')->insertOrIgnore([
            ['id_tipologia_segnalazione' => 1,  'descrizione' => 'IMPIANTO ELETTRICO',    'icona' => 'fas fa-bolt',       'id_gruppo' => 1],
            ['id_tipologia_segnalazione' => 2,  'descrizione' => 'INFISSI E PORTE',       'icona' => 'fas fa-door-open',  'id_gruppo' => 1],
            ['id_tipologia_segnalazione' => 3,  'descrizione' => 'IMPIANTO IDRICO',       'icona' => 'fas fa-faucet',     'id_gruppo' => 1],
            ['id_tipologia_segnalazione' => 4,  'descrizione' => 'SFALCI E POTATURE',     'icona' => 'fab fa-pagelines',  'id_gruppo' => 4],
            ['id_tipologia_segnalazione' => 5,  'descrizione' => 'MANUTENZIONE EDILE',    'icona' => 'fas fa-building',   'id_gruppo' => 1],
            ['id_tipologia_segnalazione' => 6,  'descrizione' => 'IMPIANTO TERMICO',      'icona' => 'fas fa-fire-alt',   'id_gruppo' => 1],
            ['id_tipologia_segnalazione' => 7,  'descrizione' => 'RETE TELEFONICA / DATI','icona' => 'fas fa-globe',      'id_gruppo' => 1],
            ['id_tipologia_segnalazione' => 8,  'descrizione' => 'VERDE E GIARDINI',      'icona' => 'fas fa-seedling',   'id_gruppo' => 1],
            ['id_tipologia_segnalazione' => 9,  'descrizione' => 'ARREDI',                'icona' => 'fas fa-chair',      'id_gruppo' => 1],
            ['id_tipologia_segnalazione' => 10, 'descrizione' => 'GIOCHI',                'icona' => 'fas fa-running',    'id_gruppo' => 5],
            ['id_tipologia_segnalazione' => 11, 'descrizione' => 'SFALCI E POTATURE',     'icona' => 'fab fa-pagelines',  'id_gruppo' => 5],
            ['id_tipologia_segnalazione' => 12, 'descrizione' => 'FONTANE PUBBLICHE',     'icona' => 'fas fa-faucet',     'id_gruppo' => 5],
        ]);

        DB::table('db_specializzazioni')->insertOrIgnore([
            ['id_specializzazione' => 1, 'descrizione' => 'Edifici Civili ed Industriali (OG1)'],
            ['id_specializzazione' => 2, 'descrizione' => 'Lavori stradali (OG3)'],
            ['id_specializzazione' => 3, 'descrizione' => 'Impianti Elettrici e Trasmissione Dati'],
            ['id_specializzazione' => 4, 'descrizione' => 'Falegnameria'],
            ['id_specializzazione' => 5, 'descrizione' => 'Infissi e Porte'],
            ['id_specializzazione' => 6, 'descrizione' => 'Gestione Verde Pubblico'],
            ['id_specializzazione' => 7, 'descrizione' => 'Impresa di Pulizie'],
            ['id_specializzazione' => 8, 'descrizione' => 'Disinfestazione e Derattizzazione'],
        ]);

        DB::table('parametri')->insertOrIgnore([
            ['id_parametro' => 1, 'descrizione' => 'AZIONI_PER_RIGA',              'valore' => '4'],
            ['id_parametro' => 2, 'descrizione' => 'TIPI_SEGNALAZIONE_PER_RIGA',  'valore' => '4'],
            ['id_parametro' => 3, 'descrizione' => 'CATEGORIE_SEGNALAZIONI_RIGA', 'valore' => '5'],
            ['id_parametro' => 4, 'descrizione' => 'LATITUDINE_STANDARD',         'valore' => '42.5136032'],
            ['id_parametro' => 5, 'descrizione' => 'LONGITUDINE_STANDARD',        'valore' => '14.1516203'],
            ['id_parametro' => 6, 'descrizione' => 'ZOOM_STANDARD',               'valore' => '18'],
            ['id_parametro' => 7, 'descrizione' => 'MIN_SEGNALAZIONI_TABELLA_DINAMICA', 'valore' => '5'],
            ['id_parametro' => 8, 'descrizione' => 'STATISTICA_GIORNI_CHIUSURA1', 'valore' => '5'],
            ['id_parametro' => 9, 'descrizione' => 'STATISTICA_GIORNI_CHIUSURA2', 'valore' => '10'],
        ]);
    }
}
