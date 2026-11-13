@include('segnalazioni.scheda.pannelli._semplice', [
    'nome' => 'sospendi', 'titolo' => 'Sospendi', 'azione' => $azioniDisponibili->firstWhere('codice', 'sospendi'),
    'etichettaNota' => 'Motivo della sospensione', 'notaObbligatoria' => true, 'bottone' => 'Sospendi',
    'intro' => 'Il lavoro si ferma finché non lo riprendi. Il motivo compare nel prossimo passo.',
])
