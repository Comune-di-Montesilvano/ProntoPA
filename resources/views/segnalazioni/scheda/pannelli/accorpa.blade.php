@include('segnalazioni.scheda.pannelli._candidati', [
    'nome' => 'accorpa', 'titolo' => 'Accorpa a una segnalazione aperta',
    'urlCandidati' => route('segnalazioni.candidati-accorpa', $segnalazione),
    'action' => route('segnalazioni.accorpa', $segnalazione),
    'campo' => 'id_destinazione', 'nascosti' => [],
    'intro' => 'Stesso problema già segnalato? Scegli la segnalazione aperta in cui farla confluire.',
    'riepilogo' => 'Questa segnalazione si chiude come "Accorpata". Chi l\'ha inviata segue la scelta e ne riceve gli aggiornamenti; quella scelta risulta segnalata di nuovo e chi ci lavora viene avvisato. Foto e adesioni passano a quella scelta.',
    'bottone' => 'Accorpa', 'vuoto' => 'Nessuna segnalazione aperta simile: cerca per numero o testo.',
])
