@include('segnalazioni.scheda.pannelli._semplice', [
    'nome' => 'azione-'.$azione->id_azione, 'titolo' => $azione->descrizione, 'azione' => $azione,
    'etichettaNota' => $azione->codice === 'rifiuta_preventivo' ? 'Motivo' : 'Nota (facoltativa)', 'notaObbligatoria' => $azione->codice === 'rifiuta_preventivo', 'bottone' => $azione->descrizione,
])
