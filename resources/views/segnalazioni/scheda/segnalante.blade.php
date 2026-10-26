{{-- Lettura segnalante: a che punto è, in parole semplici, e un posto dove scrivere al comune. --}}
@use('App\Enums\SegnalazioneStato')
@php
    $stato = $segnalazione->statoEnum();
    $titolo = $segnalazione->titolo_generato ?: \Illuminate\Support\Str::limit((string) $segnalazione->testo_segnalazione, 90);
    $puoScrivere = ! in_array($stato, [SegnalazioneStato::ARCHIVIATA, SegnalazioneStato::DUPLICATA, SegnalazioneStato::ANNULLATA], true);
    $madre = $stato === SegnalazioneStato::DUPLICATA ? $segnalazione->segnalazioneMadre : null;
    $ultimaRisposta = $attivita->first(fn ($v) => $v['tipo'] === 'nota' && $v['autore'] !== 'Tu');
@endphp

<div class="mx-auto flex w-full max-w-[880px] flex-col gap-5">
    <header class="flex flex-col gap-1">
        <p class="m-0 text-sm text-[var(--slate-600)]">Segnalazione <span class="pa-mono font-semibold">#{{ $segnalazione->id_segnalazione }}</span> del {{ $segnalazione->data_segnalazione?->format('d/m/Y') }}</p>
        <h1 class="m-0 text-2xl font-bold text-[var(--ink)] break-words">{{ $titolo }}</h1>
    </header>

    <section class="pa-card pa-passo pa-passo-{{ $prossimoPasso['tono'] }} p-5 flex flex-col gap-2" aria-live="polite">
        <h2 class="m-0 text-xl font-bold text-[var(--ink)]">{{ $prossimoPasso['titolo'] }}</h2>
        @if($madre && auth()->user()->can('view', $madre))
            <p class="m-0 text-[var(--slate-700)]">Era già stata segnalata: segui gli aggiornamenti nella
                <a class="font-semibold text-[var(--ente-primary)] underline" href="{{ route('segnalazioni.show', $madre->id_segnalazione) }}">#{{ $madre->id_segnalazione }}</a>.</p>
        @elseif($prossimoPasso['sottotitolo'])
            <p class="m-0 text-[var(--slate-700)]">{{ $prossimoPasso['sottotitolo'] }}</p>
        @endif
        @if($ultimaRisposta)
            <p class="m-0 mt-1 text-sm text-[var(--slate-600)]">Ultimo aggiornamento del {{ $ultimaRisposta['quando']->format('d/m') }}: «{{ \Illuminate\Support\Str::limit((string) $ultimaRisposta['testo'], 160) }}»</p>
        @endif
    </section>

    @include('segnalazioni.scheda._inattivita')

    @include('segnalazioni.scheda._avanzamento')

    <x-pa.card titolo="Messaggi con il comune">
        @if($puoScrivere)
            @include('segnalazioni.scheda._composer')
        @endif
        @include('segnalazioni.scheda._attivita')
    </x-pa.card>

    <x-pa.card titolo="Cosa hai segnalato">
        <p class="m-0 text-lg leading-relaxed text-[var(--ink)] whitespace-pre-wrap break-words">{{ $segnalazione->testo_segnalazione }}</p>
        @include('segnalazioni.scheda._foto', ['foto' => $fotoPrima])
        @include('segnalazioni.scheda._dove')
        <p class="m-0 text-sm text-[var(--slate-500)]">
            Inviata da {{ $segnalazione->id_utente_segnalazione === auth()->id() ? 'te' : ($segnalazione->segnalante ?: 'un altro segnalante') }}
        </p>
    </x-pa.card>
</div>
