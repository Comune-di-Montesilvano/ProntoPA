{{-- Anteprima in sola lettura (1.0.6), caricata da resources/js/anteprima.js al passaggio del mouse
     su una riga degli elenchi. Niente layout: è un frammento. Filtri come la scheda. --}}
@php
    $stato = $segnalazione->statoEnum();
    $chi = $lettura === 'gestore' && ! $segnalazione->isChiusa()
        ? ($segnalazione->nomeAssegnatario() ?? ($stato === \App\Enums\SegnalazioneStato::NUOVA ? 'da assegnare' : null))
        : null;
    $foto = $segnalazione->allegati
        ->filter(fn ($a) => $a->fase === 'prima' && $a->isFotoVisibile());
    $volte = $lettura === 'gestore' ? $segnalazione->volteSegnalata() : 1;
@endphp
<div class="pa-anteprima-corpo">
    <p class="pa-anteprima-testata">
        <span class="pa-mono font-semibold">#{{ $segnalazione->id_segnalazione }}</span>
        <x-pa.tipologia :tipologia="$segnalazione->tipologia" />
        @if($lettura === 'segnalante')
            <x-pa.stato :stato="$stato" segnalante />
        @else
            <x-pa.stato :stato="$stato" :chi="$chi" />
        @endif
    </p>

    @if(filled($segnalazione->titolo))
        <p class="pa-anteprima-titolo">{{ $segnalazione->titolo }}</p>
    @endif

    <p class="pa-anteprima-riga">
        @if($segnalazione->plesso)
            <i class="fas fa-school" aria-hidden="true"></i>
            {{ $segnalazione->plesso->nomeLeggibile() }}@if($segnalazione->plesso->indirizzo) · {{ $segnalazione->plesso->indirizzo }}@endif
        @else
            Senza sede
        @endif
    </p>
    <p class="pa-anteprima-riga">
        Segnalata il {{ $segnalazione->data_segnalazione?->format('d/m/Y') }}
        @if($lettura === 'gestore' && $segnalazione->utente) da {{ $segnalazione->utente->name }}@endif
        @if($volte > 1) · segnalata {{ $volte }} volte @endif
    </p>

    @if(filled($segnalazione->testo_segnalazione))
        <p class="pa-anteprima-testo">{{ $segnalazione->testo_segnalazione }}</p>
    @endif

    @if($foto->isNotEmpty())
        <div class="pa-anteprima-foto">
            @foreach($foto->take(3) as $allegato)
                <img src="{{ route('segnalazioni.allegati.foto', [$segnalazione->id_segnalazione, $allegato->id_allegato]) }}"
                     alt="{{ $allegato->nome_originale }}" loading="lazy">
            @endforeach
            @if($foto->count() > 3)<span>+{{ $foto->count() - 3 }}</span>@endif
        </div>
    @endif

    @if($ultima)
        <p class="pa-anteprima-ultima">
            <span class="pa-anteprima-etichetta">Ultimo</span>
            {{ $ultima['quando']->format('d/m H:i') }} · <strong>{{ $ultima['autore'] }}</strong> {{ $ultima['frase'] }}
            @php $dettaglio = $ultima['nota'] ?? $ultima['testo']; @endphp
            @if(filled($dettaglio))<span class="pa-anteprima-nota">“{{ \Illuminate\Support\Str::limit($dettaglio, 180) }}”</span>@endif
        </p>
    @endif

    <p class="pa-anteprima-riga">
        @if($segnalazione->isChiusa())
            Chiusa il {{ $segnalazione->data_chiusura?->format('d/m/Y') }}
        @else
            <span class="pa-anteprima-etichetta">Scadenza</span> <x-pa.scadenza :segnalazione="$segnalazione" />
        @endif
    </p>
</div>
