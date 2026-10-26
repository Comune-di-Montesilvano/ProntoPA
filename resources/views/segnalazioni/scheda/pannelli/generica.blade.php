@include('segnalazioni.scheda.pannelli._semplice', [
    'nome' => 'azione-'.$azione->id_azione, 'titolo' => $azione->descrizione, 'azione' => $azione,
    'etichettaNota' => 'Nota (facoltativa)', 'notaObbligatoria' => false, 'bottone' => $azione->descrizione,
])
