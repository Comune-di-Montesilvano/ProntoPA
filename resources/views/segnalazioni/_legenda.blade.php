{{-- Legenda dei colori (1.0.4): stato = pallino, priorità = etichetta, tipologia = sfondo pieno.
     Accanto all'elenco sugli schermi larghi, richiudibile sopra l'elenco sugli altri. --}}
@php
    $stati = [
        \App\Enums\SegnalazioneStato::NUOVA, \App\Enums\SegnalazioneStato::IN_CARICO, \App\Enums\SegnalazioneStato::ASSEGNATA_OPERATORE,
        \App\Enums\SegnalazioneStato::ASSEGNATA_IMPRESA, \App\Enums\SegnalazioneStato::ATTESA_RISPOSTA,
        \App\Enums\SegnalazioneStato::COMPLETATA, \App\Enums\SegnalazioneStato::ANNULLATA,
    ];
    $tipologieLegenda = \App\Models\TipologiaSegnalazione::attive()->orderBy('descrizione')->get();
@endphp
<div class="pa-legenda">
    <section>
        <h3>Stato</h3>
        <ul>
            @foreach($stati as $st)
                <li><x-pa.stato :stato="$st" /></li>
            @endforeach
        </ul>
    </section>
    <section>
        <h3>Priorità</h3>
        <ul class="pa-legenda-righe">
            <li><x-pa.priorita :livello="4" /></li>
            <li><x-pa.priorita :livello="3" /></li>
            <li><x-pa.priorita :livello="1" /></li>
            <li class="pa-legenda-nota">Media: nessuna etichetta</li>
        </ul>
    </section>
    <section>
        <h3>Tipologia</h3>
        <ul class="pa-legenda-righe">
            @foreach($tipologieLegenda as $t)
                <li><x-pa.tipologia :tipologia="$t" /></li>
            @endforeach
        </ul>
    </section>
    <section>
        <h3>Scadenza</h3>
        <p class="pa-legenda-nota"><span class="font-semibold text-[var(--rose)]">Rosso</span>: scaduta da meno di un giorno o scade entro 24 ore.</p>
    </section>
</div>
