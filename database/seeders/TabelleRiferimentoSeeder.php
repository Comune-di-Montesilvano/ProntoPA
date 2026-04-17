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
            ['id_stato' => 1,  'descrizione' => 'IN ATTESA DI ESAME',            'iniziale' => 1, 'in_carico' => 0, 'id_gestione' => 0, 'sospesa' => 1, 'chiusura' => 0, 'colore_sfondo' => 'primary'],
            ['id_stato' => 2,  'descrizione' => 'IN CARICO',                      'iniziale' => 0, 'in_carico' => 1, 'id_gestione' => 1, 'sospesa' => 0, 'chiusura' => 0, 'colore_sfondo' => 'none'],
            ['id_stato' => 3,  'descrizione' => 'COMPLETATA',                     'iniziale' => 0, 'in_carico' => 0, 'id_gestione' => 0, 'sospesa' => 0, 'chiusura' => 1, 'colore_sfondo' => 'none'],
            ['id_stato' => 4,  'descrizione' => 'ANNULLATA',                      'iniziale' => 0, 'in_carico' => 0, 'id_gestione' => 0, 'sospesa' => 0, 'chiusura' => 1, 'colore_sfondo' => 'none'],
            ['id_stato' => 5,  'descrizione' => 'ARCHIVIATA',                     'iniziale' => 0, 'in_carico' => 0, 'id_gestione' => 0, 'sospesa' => 0, 'chiusura' => 1, 'colore_sfondo' => 'none'],
            ['id_stato' => 6,  'descrizione' => "valutazione FATTIBILITA'",       'iniziale' => 0, 'in_carico' => 1, 'id_gestione' => 1, 'sospesa' => 0, 'chiusura' => 0, 'colore_sfondo' => 'danger'],
            ['id_stato' => 7,  'descrizione' => 'ASSEGNATA AD IMPRESA',           'iniziale' => 0, 'in_carico' => 0, 'id_gestione' => 1, 'sospesa' => 0, 'chiusura' => 0, 'colore_sfondo' => 'primary'],
            ['id_stato' => 8,  'descrizione' => 'ASSEGNATO SQUADRA TECNICA',      'iniziale' => 0, 'in_carico' => 0, 'id_gestione' => 1, 'sospesa' => 0, 'chiusura' => 0, 'colore_sfondo' => 'success'],
            ['id_stato' => 9,  'descrizione' => 'IN APPROVAZIONE PREVENTIVO',     'iniziale' => 0, 'in_carico' => 0, 'id_gestione' => 1, 'sospesa' => 0, 'chiusura' => 0, 'colore_sfondo' => 'warning'],
            ['id_stato' => 10, 'descrizione' => 'ATTESA COLLAUDO',                'iniziale' => 0, 'in_carico' => 0, 'id_gestione' => 1, 'sospesa' => 0, 'chiusura' => 0, 'colore_sfondo' => 'none'],
            ['id_stato' => 11, 'descrizione' => 'PREVENTIVO ACCETTATO',           'iniziale' => 0, 'in_carico' => 0, 'id_gestione' => 1, 'sospesa' => 0, 'chiusura' => 0, 'colore_sfondo' => 'none'],
            ['id_stato' => 12, 'descrizione' => 'SOSPESA',                        'iniziale' => 0, 'in_carico' => 1, 'id_gestione' => 0, 'sospesa' => 1, 'chiusura' => 0, 'colore_sfondo' => 'secondary'],
            ['id_stato' => 13, 'descrizione' => 'attesa pareri TECNICO-FINANZIARIO','iniziale' => 0,'in_carico' => 1,'id_gestione' => 1,'sospesa' => 1, 'chiusura' => 0, 'colore_sfondo' => 'danger'],
            ['id_stato' => 14, 'descrizione' => 'in corso SOPRALLUOGO',           'iniziale' => 0, 'in_carico' => 1, 'id_gestione' => 1, 'sospesa' => 1, 'chiusura' => 0, 'colore_sfondo' => 'warning'],
        ]);

        DB::table('db_azioni')->insertOrIgnore([
            ['id_azione' => 1,  'descrizione' => 'ASSEGNA AD IMPRESA',         'id_stato_segnalazione' => 7,  'competenza_azione' => 0, 'colore' => 'secondary', 'flag_appalto' => 1, 'flag_operatore' => 0, 'flag_notifica' => 1, 'ordine' => 0, 'parametri_filtro' => '{"stato_2":1,"stato_7":1,"tipologia_1":1,"tipologia_2":1,"tipologia_3":1,"profilo_1":1}'],
            ['id_azione' => 2,  'descrizione' => 'ASSEGNA AD OPERATORE',       'id_stato_segnalazione' => 8,  'competenza_azione' => 0, 'colore' => 'primary',   'flag_appalto' => 0, 'flag_operatore' => 1, 'flag_notifica' => 1, 'ordine' => 0, 'parametri_filtro' => '{"stato_2":1,"stato_8":1,"stato_12":1,"stato_13":1,"stato_14":1,"tipologia_1":1,"tipologia_2":1,"tipologia_3":1,"profilo_1":1}'],
            ['id_azione' => 3,  'descrizione' => 'CHIUDI',                     'id_stato_segnalazione' => 3,  'competenza_azione' => 0, 'colore' => 'danger',    'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 1, 'ordine' => 0, 'parametri_filtro' => '{"stato_1":1,"stato_2":1,"stato_7":1,"stato_8":1,"stato_10":1,"stato_12":1,"stato_14":1,"tipologia_1":1,"tipologia_2":1,"tipologia_3":1,"profilo_1":1}'],
            ['id_azione' => 4,  'descrizione' => 'PRESENTA PREVENTIVO',        'id_stato_segnalazione' => 9,  'competenza_azione' => 1, 'colore' => 'primary',   'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 0, 'ordine' => 0, 'parametri_filtro' => ''],
            ['id_azione' => 5,  'descrizione' => 'IN PROGRAMMA',               'id_stato_segnalazione' => 8,  'competenza_azione' => 1, 'colore' => 'primary',   'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 0, 'ordine' => 0, 'parametri_filtro' => ''],
            ['id_azione' => 6,  'descrizione' => 'PROPONE CHIUSURA',           'id_stato_segnalazione' => 10, 'competenza_azione' => 1, 'colore' => 'primary',   'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 0, 'ordine' => 0, 'parametri_filtro' => ''],
            ['id_azione' => 7,  'descrizione' => 'ACCETTA PREVENTIVO',         'id_stato_segnalazione' => 11, 'competenza_azione' => 0, 'colore' => 'danger',    'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 0, 'ordine' => 0, 'parametri_filtro' => '{"stato_1":1,"stato_8":1,"stato_9":1,"tipologia_1":1,"tipologia_2":1,"tipologia_3":1,"profilo_1":1}'],
            ['id_azione' => 8,  'descrizione' => 'ARCHIVIA (NON FINANZIATO)',   'id_stato_segnalazione' => 5,  'competenza_azione' => 0, 'colore' => 'secondary', 'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 0, 'ordine' => 0, 'parametri_filtro' => '{"stato_2":1,"stato_6":1,"stato_9":1,"stato_10":1,"stato_13":1,"stato_14":1,"tipologia_1":1,"tipologia_2":1,"tipologia_3":1,"profilo_1":1}'],
            ['id_azione' => 9,  'descrizione' => 'IN CARICO OPERAI',           'id_stato_segnalazione' => 8,  'competenza_azione' => 0, 'colore' => 'warning',   'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 0, 'ordine' => 0, 'parametri_filtro' => ''],
            ['id_azione' => 10, 'descrizione' => 'fare SOPRALLUOGO',           'id_stato_segnalazione' => 14, 'competenza_azione' => 0, 'colore' => 'secondary', 'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 0, 'ordine' => 0, 'parametri_filtro' => ''],
            ['id_azione' => 11, 'descrizione' => 'VALUTAZIONE PARERI',         'id_stato_segnalazione' => 13, 'competenza_azione' => 0, 'colore' => 'secondary', 'flag_appalto' => 0, 'flag_operatore' => 1, 'flag_notifica' => 0, 'ordine' => 0, 'parametri_filtro' => ''],
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
