@include('segnalazioni.scheda.pannelli._candidati', [
    'nome' => 'collega', 'titolo' => 'Collega a una riparazione precedente',
    'urlCandidati' => route('segnalazioni.candidati-collega', $segnalazione),
    'action' => route('segnalazioni.azione', $segnalazione->id_segnalazione),
    'campo' => 'id_segnalazione_correlata',
    'nascosti' => ['id_azione' => $azioniDisponibili->firstWhere('codice', 'collega')->id_azione],
    'intro' => 'Il guasto si è ripresentato dopo una riparazione? Scegli la segnalazione già chiusa.',
    'riepilogo' => 'Questa segnalazione resta aperta e viene segnata come "Ricorrente", con il riferimento alla riparazione precedente.',
    'bottone' => 'Collega', 'vuoto' => 'Nessuna riparazione chiusa negli ultimi 24 mesi per questa sede e tipologia: cerca per numero o testo.',
])
