{{-- Lettura lavoro (ditta, operaio): ordine di lavoro pensato per il telefono. --}}
@php
    $titolo = $segnalazione->titolo_generato ?: \Illuminate\Support\Str::limit((string) $segnalazione->testo_segnalazione, 90);
    $assegnazione = $segnalazione->storicoStati
        ->filter(fn ($e) => in_array((int) $e->id_stato_segnalazione, [3, 4], true))
        ->sortBy('data_registrazione')->first();
    $chiusa = $segnalazione->isChiusa();
@endphp

<div class="mx-auto flex w-full max-w-3xl flex-col gap-5">
    <header class="flex flex-col gap-2">
        <p class="m-0 text-sm text-[var(--slate-600)]">
            <span class="pa-mono font-semibold">#{{ $segnalazione->id_segnalazione }}</span>
            · {{ $segnalazione->tipologia?->descrizione ?? '—' }}
        </p>
        <h1 class="m-0 text-2xl font-bold text-[var(--ink)] break-words">{{ $titolo }}</h1>
        <div class="flex flex-wrap items-center gap-2">
            <x-pa.prio :livello="$segnalazione->livello_priorita" />
            <x-pa.scadenza :segnalazione="$segnalazione" estesa />
        </div>
    </header>

    @include('segnalazioni.scheda._prossimo-passo', ['grande' => true])
    @include('segnalazioni.scheda._inattivita')

    @if(auth()->user()->hasRole('operaio') && ($segnalazione->squadraAssegnata || $segnalazione->id_operatore_assegnato))
        <x-pa.card titolo="Chi ci lavora">
            @include('segnalazioni.scheda._chi-ci-lavora')
        </x-pa.card>
    @endif

    <x-pa.card titolo="Dove">
        @include('segnalazioni.scheda._dove', ['conAzioni' => true])
    </x-pa.card>

    <x-pa.card titolo="Cosa è stato segnalato">
        <p class="m-0 text-lg leading-relaxed text-[var(--ink)] whitespace-pre-wrap break-words">{{ $segnalazione->testo_segnalazione }}</p>
        @include('segnalazioni.scheda._foto', ['foto' => $fotoPrima])
        @if($fotoDopo->isNotEmpty())
            <p class="m-0 pa-sezione-titolo mt-2">Foto del lavoro finito</p>
            @include('segnalazioni.scheda._foto', ['foto' => $fotoDopo])
        @endif
    </x-pa.card>

    <x-pa.card titolo="Messaggi con il comune">
        @unless($chiusa)
            @include('segnalazioni.scheda._composer')
        @endunless
        @include('segnalazioni.scheda._attivita')
    </x-pa.card>

    <p class="m-0 text-sm text-[var(--slate-500)]">
        @if($assegnazione) Assegnata il {{ \Illuminate\Support\Carbon::parse($assegnazione->data_registrazione)->format('d/m/Y') }} @endif
        @if($segnalazione->id_appalto && $segnalazione->appalto) · Appalto: {{ $segnalazione->appalto->descrizione }} @endif
    </p>
</div>

@can('comunicaFineLavori', $segnalazione)
    @unless($segnalazione->haFineLavori())
        @include('segnalazioni.scheda.pannelli.fine-lavori')
    @endunless
@endcan
@if($azioniDisponibili->contains('codice', 'presenta_preventivo'))
    @include('segnalazioni.scheda.pannelli.preventivo')
@endif
