@include('segnalazioni.scheda.pannelli._semplice', [
    'nome' => 'annulla', 'titolo' => 'Annulla la segnalazione', 'azione' => $azioniDisponibili->firstWhere('codice', 'annulla'),
    'etichettaNota' => 'Motivazione (obbligatoria)', 'notaObbligatoria' => true, 'bottone' => 'Annulla la segnalazione',
    'intro' => 'La segnalazione si chiude senza intervento.', 'pericolosa' => true,
])
