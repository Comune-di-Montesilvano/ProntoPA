<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class IstitutiPlessiSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('istituti')->insertOrIgnore([
            ['id_istituto' => 1, 'descrizione' => 'IC DELFICO',                      'codice_meccanografico' => 'PEIC82600C', 'dirigente' => 'MEDINA vincenza',   'email' => 'peic82600c@istruzione.it', 'recapiti' => '085.8894210'],
            ['id_istituto' => 2, 'descrizione' => 'IC VILLA VERROCCHIO',              'codice_meccanografico' => 'PEIC827008', 'dirigente' => 'ROMANO enrica',     'email' => 'peic827008@istruzione.it', 'recapiti' => '085.8894310'],
            ['id_istituto' => 3, 'descrizione' => 'IC SILONE',                        'codice_meccanografico' => 'PEIC828004', 'dirigente' => 'MARTORELLA roberta','email' => 'peic828004@istruzione.it', 'recapiti' => '085.8894410'],
            ['id_istituto' => 4, 'descrizione' => 'IC RODARI',                        'codice_meccanografico' => 'PEIC83900E', 'dirigente' => 'FORCELLA adriano',  'email' => 'peic83900e@istruzione.it', 'recapiti' => '085.8894510'],
            ['id_istituto' => 5, 'descrizione' => 'DIREZIONE DIDATTICA MONTESILVANO', 'codice_meccanografico' => 'PEEE037001', 'dirigente' => 'SCORRANO mauro',    'email' => 'peee037001@istruzione.it', 'recapiti' => '085.8894610'],
        ]);

        DB::table('profili')->insertOrIgnore([
            ['id_profilo' => 1, 'descrizione' => 'UFFICIO TECNICO (LL.PP.)',        'limita_istituto' => 0, 'id_istituto' => null, 'limita_segnalazioni' => null, 'id_tipologia_segnalazione' => null],
            ['id_profilo' => 2, 'descrizione' => 'URP',                             'limita_istituto' => 0, 'id_istituto' => null, 'limita_segnalazioni' => null, 'id_tipologia_segnalazione' => null],
            ['id_profilo' => 3, 'descrizione' => 'IC DELFICO',                      'limita_istituto' => 1, 'id_istituto' => 1,    'limita_segnalazioni' => 1,    'id_tipologia_segnalazione' => null],
            ['id_profilo' => 5, 'descrizione' => 'UFFICIO TECNICO (VERDE)',         'limita_istituto' => 0, 'id_istituto' => null, 'limita_segnalazioni' => 3,    'id_tipologia_segnalazione' => 4],
            ['id_profilo' => 6, 'descrizione' => 'IC VILLA VERROCCHIO',             'limita_istituto' => 1, 'id_istituto' => 2,    'limita_segnalazioni' => 1,    'id_tipologia_segnalazione' => null],
            ['id_profilo' => 7, 'descrizione' => 'IC SILONE',                       'limita_istituto' => 1, 'id_istituto' => 3,    'limita_segnalazioni' => 1,    'id_tipologia_segnalazione' => null],
            ['id_profilo' => 8, 'descrizione' => 'IC RODARI',                       'limita_istituto' => 1, 'id_istituto' => 4,    'limita_segnalazioni' => 1,    'id_tipologia_segnalazione' => null],
            ['id_profilo' => 9, 'descrizione' => 'DIREZIONE DIDATTICA MONTESILVANO','limita_istituto' => 1, 'id_istituto' => 5,    'limita_segnalazioni' => 1,    'id_tipologia_segnalazione' => null],
        ]);

        // Plessi (33 scuole) - inserimento completo
        DB::table('plessi')->insertOrIgnore([
            ['id_plesso' => 1,  'id_istituto' => 1, 'nome' => 'scuola secondaria via Verrotti DELFICO',  'codice_meccanografico' => 'PEMM82601D', 'indirizzo' => 'Via Verrotti,600 - Montesilvano',        'referente' => 'LA VELLA annalisa',    'email' => 'peic82600c@istruzione.it', 'recapiti' => '085.8894250'],
            ['id_plesso' => 2,  'id_istituto' => 1, 'nome' => 'scuola primaria FANNY DI BLASIO',         'codice_meccanografico' => 'PEEE82602G', 'indirizzo' => 'Piazza Diaz - Montesilvano',             'referente' => 'SPITALERI BENEDETTA', 'email' => 'peic82600c@istruzione.it', 'recapiti' => '085.8894220'],
            ['id_plesso' => 3,  'id_istituto' => 1, 'nome' => "scuola secondaria SUCCURSALE",            'codice_meccanografico' => 'PEEE82602G', 'indirizzo' => "Via D'annunzio - Montesilvano",          'referente' => 'LA VELLA annalisa',   'email' => 'peic82600c@istruzione.it', 'recapiti' => '085.8894230'],
            ['id_plesso' => 4,  'id_istituto' => 1, 'nome' => "scuola dell'infanzia DE ZELIS",           'codice_meccanografico' => 'PEAA82604C', 'indirizzo' => 'Piazza Marconi - Montesilvano',          'referente' => 'LOPA Adelina',        'email' => 'peic82600c@istruzione.it', 'recapiti' => '085.8894240'],
            ['id_plesso' => 5,  'id_istituto' => 2, 'nome' => 'scuola secondaria VILLA VERROCCHIO',      'codice_meccanografico' => 'PEMM827019', 'indirizzo' => 'via olona - montesilvano',               'referente' => 'MUFFO claudia',       'email' => 'peic827008@istruzione.it', 'recapiti' => '085.4453744'],
            ['id_plesso' => 6,  'id_istituto' => 2, 'nome' => 'scuola primaria DON CICCONETTI',          'codice_meccanografico' => 'PEEE82701A', 'indirizzo' => 'via adige - montesilvano',               'referente' => "D'ARMI olga",         'email' => 'peic827008@istruzione.it', 'recapiti' => '085.835691'],
            ['id_plesso' => 7,  'id_istituto' => 2, 'nome' => 'scuola primaria VERROTTI SUD',            'codice_meccanografico' => 'PEEE82702B', 'indirizzo' => 'via reno - montesilvano',                'referente' => 'GISMANO cristina',    'email' => 'peic827008@istruzione.it', 'recapiti' => '085.4491331'],
            ['id_plesso' => 8,  'id_istituto' => 2, 'nome' => "scuola dell'infanzia DEZIO",              'codice_meccanografico' => 'PEAA827015', 'indirizzo' => 'via adda - montesilvano',                'referente' => 'SCHIAVONE anna maria','email' => 'peic827008@istruzione.it', 'recapiti' => '085.8362188'],
            ['id_plesso' => 10, 'id_istituto' => 3, 'nome' => 'scuola secondaria SILONE',               'codice_meccanografico' => 'PEMM828015', 'indirizzo' => 'via san gottardo - montesilvano',        'referente' => 'ROSSI rosaria',       'email' => 'peic828004@istruzione.it', 'recapiti' => '085.4682846'],
            ['id_plesso' => 11, 'id_istituto' => 3, 'nome' => 'scuola secondaria MONTESILVANO COLLE',   'codice_meccanografico' => 'PEMM828015', 'indirizzo' => 'corso vittorio emanuele - montesilvano', 'referente' => 'MICELI maria grazia', 'email' => 'peic828004@istruzione.it', 'recapiti' => '085.4680777'],
            ['id_plesso' => 12, 'id_istituto' => 3, 'nome' => 'scuola primaria VILLA CARMINE',          'codice_meccanografico' => 'PEEE828038', 'indirizzo' => 'via san gottardo - montesilvano',        'referente' => 'DI FRANCESCO lucia',  'email' => 'peic828004@istruzione.it', 'recapiti' => '085.4681919'],
            ['id_plesso' => 13, 'id_istituto' => 3, 'nome' => 'scuola primaria MONTESILVANO COLLE',     'codice_meccanografico' => 'PEEE828027', 'indirizzo' => 'corso vittorio emenuele - montesilvano', 'referente' => 'PIZII silvana',       'email' => 'peic828004@istruzione.it', 'recapiti' => '085.4682432'],
            ['id_plesso' => 14, 'id_istituto' => 3, 'nome' => "scuola dell'infanzia MONTESILVANO COLLE",'codice_meccanografico' => 'PEAA828044', 'indirizzo' => 'corso vittorio emanuele - montesilvano', 'referente' => 'CARUTA elena',        'email' => 'peic828004@istruzione.it', 'recapiti' => '085.4680589'],
            ['id_plesso' => 15, 'id_istituto' => 3, 'nome' => "scuola dell'infanzia COLLEMARE",         'codice_meccanografico' => 'PEAA828033', 'indirizzo' => 'contrada macchiano - montesilvano',      'referente' => 'MAMBELLA cristina',   'email' => 'peic828004@istruzione.it', 'recapiti' => '085.4454202'],
            ['id_plesso' => 16, 'id_istituto' => 3, 'nome' => "scuola dell'infanzia COLONNETTA",        'codice_meccanografico' => 'PEAA828022', 'indirizzo' => 'via almirante - montesilvano',           'referente' => 'GIANCATERINA simona', 'email' => 'peic828004@istruzione.it', 'recapiti' => null],
            ['id_plesso' => 17, 'id_istituto' => 4, 'nome' => 'scuola primaria SALINE',                 'codice_meccanografico' => 'PEEE83901L', 'indirizzo' => 'via costa - montesilvano',               'referente' => "D'ANTEO Antonella",   'email' => 'peic83900e@istruzione.it', 'recapiti' => '085.4680606'],
            ['id_plesso' => 18, 'id_istituto' => 4, 'nome' => "scuola dell'infanzia VESTINA",           'codice_meccanografico' => 'PEAA83903D', 'indirizzo' => 'via vestina 322 - montesilvano',         'referente' => 'POMPOSO Meri',        'email' => 'peic83900e@istruzione.it', 'recapiti' => '085.4680630'],
            ['id_plesso' => 19, 'id_istituto' => 4, 'nome' => "scuola dell'infanzia FONTE D'OLMO",      'codice_meccanografico' => 'PEAA83902C', 'indirizzo' => 'via vestina 357 - montesilvano',         'referente' => 'ROBERTI Alessandra',  'email' => 'peic83900e@istruzione.it', 'recapiti' => '085.4683234'],
            ['id_plesso' => 20, 'id_istituto' => 5, 'nome' => 'scuola primaria VIA LAZIO',              'codice_meccanografico' => 'PEEE037078', 'indirizzo' => 'via lazio - montesilvano',               'referente' => "D'Angelo Costanza",   'email' => 'peee037001@istruzione.it', 'recapiti' => '085.4454744'],
            ['id_plesso' => 21, 'id_istituto' => 5, 'nome' => 'scuola primaria VIALE ABRUZZO',          'codice_meccanografico' => 'PEEE037709', 'indirizzo' => 'viale abruzzo - montesilvano',           'referente' => 'PEZZUOLO francesca',  'email' => 'peee037001@istruzione.it', 'recapiti' => '085.835844'],
            ['id_plesso' => 22, 'id_istituto' => 5, 'nome' => "scuola primaria VITELLO D'ORO",          'codice_meccanografico' => 'PEEE037709', 'indirizzo' => "via vitello d'oro - montesilvano",       'referente' => 'FORTUNA eleonora',    'email' => 'peee037001@istruzione.it', 'recapiti' => '085.834452'],
            ['id_plesso' => 23, 'id_istituto' => 5, 'nome' => "scuola primaria VALLE D'AOSTA",          'codice_meccanografico' => 'PEEE037078', 'indirizzo' => "via valle d'aosta - montesilvano",       'referente' => 'BALDASSARRE Anna Maria','email' => 'peee037001@istruzione.it','recapiti' => '085.4452217'],
            ['id_plesso' => 24, 'id_istituto' => 5, 'nome' => "scuola dell'infanzia VIA LAZIO",         'codice_meccanografico' => 'PEAA037051', 'indirizzo' => 'via lazio - montesilvano',               'referente' => 'SACCHETTI Marialetizia','email' => 'peee037001@istruzione.it','recapiti' => '085.4454744'],
            ['id_plesso' => 25, 'id_istituto' => 5, 'nome' => "scuola dell'infanzia VALLE D'AOSTA",     'codice_meccanografico' => 'PEAA03704X', 'indirizzo' => "via valle d'aosta - montesilvano",       'referente' => 'LASTELLA Nicoletta',  'email' => 'peee037001@istruzione.it', 'recapiti' => '085.4452217'],
            ['id_plesso' => 26, 'id_istituto' => 5, 'nome' => "scuola dell'infanzia VITELLO D'ORO",     'codice_meccanografico' => 'PEAA037107', 'indirizzo' => "via vitello d'oro - montesilvano",       'referente' => 'SAVINI Simonetta',    'email' => 'peee037001@istruzione.it', 'recapiti' => '085.834452'],
            ['id_plesso' => 27, 'id_istituto' => 5, 'nome' => "scuola dell'infanzia VIA DANTE",         'codice_meccanografico' => 'PEAA03701R', 'indirizzo' => 'via dante - montesilvano',               'referente' => 'COSTANTINI Rossella', 'email' => 'peee037001@istruzione.it', 'recapiti' => '085.834001'],
            ['id_plesso' => 28, 'id_istituto' => 4, 'nome' => 'segreteria IC RODARI',                   'codice_meccanografico' => 'PEIC83900E', 'indirizzo' => 'via Magellano - Montesilvano',           'referente' => 'FORCELLA Adriano',    'email' => 'peic83900e@istruzione.it', 'recapiti' => '085.4682259'],
            ['id_plesso' => 29, 'id_istituto' => 5, 'nome' => 'UFFICI DIREZIONE DIDATTICA MONTESILVANO','codice_meccanografico' => 'PEEE037001', 'indirizzo' => 'VIA CAMPO IMPERATORE',                   'referente' => 'SCORRANO Mauro',      'email' => 'peee037001@istruzione.it', 'recapiti' => '0854452801'],
            ['id_plesso' => 30, 'id_istituto' => 2, 'nome' => 'segreteria IC VILLA VERROCCHIO',         'codice_meccanografico' => 'PEIC827008', 'indirizzo' => 'via olona - montesilvano',               'referente' => 'BARTOLOMEO giuliana', 'email' => 'peic827008@istruzione.it', 'recapiti' => '085.4453744'],
            ['id_plesso' => 32, 'id_istituto' => 1, 'nome' => 'Segreteria Ic Delfico',                  'codice_meccanografico' => 'peic82600c', 'indirizzo' => 'Piazza Indro Montanelli - Palazzo Baldoni','referente' => "Dott.ssa Capuano Mara",'email' => 'peic82600c@istruzione.it', 'recapiti' => '085-8894210'],
            ['id_plesso' => 33, 'id_istituto' => 1, 'nome' => 'GALLERIA EUROPA',                        'codice_meccanografico' => 'PEEE82602G', 'indirizzo' => 'CORSO UMBERTO I 136 C/O',               'referente' => null,                  'email' => 'peic82600c@istruzione.it', 'recapiti' => '085-8894210'],
            ['id_plesso' => 34, 'id_istituto' => 1, 'nome' => 'PALA DEAN MARTIN',                       'codice_meccanografico' => 'PEEE82602G', 'indirizzo' => 'VIA  ALDO MORO',                        'referente' => null,                  'email' => 'peic82600c@istruzione.it', 'recapiti' => '0858894260'],
        ]);

        DB::table('imprese')->insertOrIgnore([
            ['id_impresa' => 1, 'ragione_sociale' => 'ENGIE Servizi SpA',                  'partita_iva' => '01698911003',  'referente' => 'andrea antinucci', 'email' => 'andrea.antinucci@engie.com', 'cellulare' => '335.5785163', 'password' => null, 'note' => "numero telefonico MARCO CAPOSANO\nandrea.antinucci@engie.com"],
            ['id_impresa' => 2, 'ragione_sociale' => 'CAPRICCI FRANCO & Co. snc',          'partita_iva' => '01277930689',  'referente' => 'capricci matteo',   'email' => 'pinoreale@vodafone.it',      'cellulare' => '333-6838941', 'password' => null, 'note' => 'lavori di falegname'],
            ['id_impresa' => 3, 'ragione_sociale' => 'PAT SERVICE srl',                    'partita_iva' => '01595660687',  'referente' => 'pino reale',        'email' => 'pinoreale@vodafone.it',      'cellulare' => '349-0565217', 'password' => null, 'note' => null],
            ['id_impresa' => 4, 'ragione_sociale' => 'ORANGE LED SERVICE marzoli paolo',   'partita_iva' => '01595660687',  'referente' => 'marzoli paolo',     'email' => 'pinoreale@vodafone.it',      'cellulare' => '337-666623',  'password' => null, 'note' => null],
            ['id_impresa' => 5, 'ragione_sociale' => 'EDIL TM trozzi maurizio',            'partita_iva' => '017444310689', 'referente' => 'trozzi maurizio',   'email' => 'pinoreale@vodafone.it',      'cellulare' => '347.3159329', 'password' => null, 'note' => null],
        ]);
    }
}
