{{-- Lettura gestore (admin, gestore): la pratica completa. --}}
@php
    // Pannello di ogni azione del workflow; quelle senza un pannello dedicato usano il generico.
    $pannelloDi = fn ($a) => match ($a->codice) {
        'assegna_operatore', 'assegna_impresa' => 'assegna',
        'sospendi' => 'sospendi',
        'chiudi' => 'chiudi',
        'annulla' => 'annulla',
        'collega' => 'collega',
        'presenta_preventivo' => 'preventivo',
        default => 'azione-'.$a->id_azione,
    };
    $nelPasso = collect($prossimoPasso['azioni'])->map(fn ($a) => $a['pannello'] ?? 'azione-'.($a['id_azione'] ?? 0))->all();
    $altreAzioni = $azioniDisponibili
        ->reject(fn ($a) => $a->codice === 'collega' || in_array($pannelloDi($a), $nelPasso, true))
        ->map(fn ($a) => ['pannello' => $pannelloDi($a), 'etichetta' => $pannelloDi($a) === 'assegna' ? 'Assegna…' : $a->descrizione])
        ->unique('pannello')->values()->all();
    $generiche = $azioniDisponibili->filter(fn ($a) => str_starts_with($pannelloDi($a), 'azione-'));
    $disponibile = fn (string $codice) => $azioniDisponibili->contains('codice', $codice);
@endphp

<div class="flex flex-col gap-5">
    @include('segnalazioni.scheda._testata')
    @include('segnalazioni.scheda._prossimo-passo')

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3 lg:grid-rows-[auto_1fr]">
        {{-- Chi ci lavora: sotto il prossimo passo anche su telefono --}}
        <div class="lg:col-start-3 lg:row-start-1">
            <x-pa.card titolo="Chi ci lavora">
                @if($disponibile('assegna_operatore') || $disponibile('assegna_impresa'))
                    <x-slot name="azione">
                        <button type="button" class="text-sm font-semibold text-[var(--ente-primary)] underline" x-data
                                x-on:click="$dispatch('apri-pannello', 'assegna')">{{ $segnalazione->id_operatore_assegnato || $segnalazione->id_appalto || $segnalazione->id_squadra_assegnata ? 'Riassegna' : 'Assegna' }}</button>
                    </x-slot>
                @endif
                @include('segnalazioni.scheda._chi-ci-lavora')
            </x-pa.card>
        </div>

        <div class="flex flex-col gap-5 lg:col-span-2 lg:col-start-1 lg:row-span-2 lg:row-start-1">
            <x-pa.card titolo="Cosa è stato segnalato">
                <p class="m-0 text-lg leading-relaxed text-[var(--ink)] whitespace-pre-wrap break-words">{{ $segnalazione->testo_segnalazione }}</p>
                @include('segnalazioni.scheda._foto', ['foto' => $fotoPrima])
                @if($fotoDopo->isNotEmpty())
                    <p class="m-0 pa-sezione-titolo mt-2">Foto dopo l'intervento</p>
                    @include('segnalazioni.scheda._foto', ['foto' => $fotoDopo])
                @endif
                <p class="m-0 text-sm text-[var(--slate-500)]">
                    Inviata il {{ $segnalazione->data_segnalazione?->format('d/m/Y H:i') }}
                    @if($segnalazione->segnalante) da {{ $segnalazione->segnalante }} @elseif($segnalazione->utente) da {{ $segnalazione->utente->name }} @endif
                    @if($segnalazione->adesioni->isNotEmpty()) · e da altre {{ $segnalazione->adesioni->count() }} persone @endif
                </p>
            </x-pa.card>

            @if($segnalazione->triage_suggerito && auth()->user()->can('update', $segnalazione))
                <x-pa.card titolo="Suggerimento dell'assistente" class="border-[var(--violet-100)]">
                    <ul class="m-0 pl-5 text-sm text-[var(--slate-700)]">
                        @if(isset($segnalazione->triage_suggerito['id_tipologia_segnalazione']))
                            <li>Tipologia: {{ \App\Models\TipologiaSegnalazione::find($segnalazione->triage_suggerito['id_tipologia_segnalazione'])?->descrizione ?? '#'.$segnalazione->triage_suggerito['id_tipologia_segnalazione'] }}</li>
                        @endif
                        @if(isset($segnalazione->triage_suggerito['livello_priorita']))
                            <li>Priorità: {{ [1 => 'Bassa', 2 => 'Media', 3 => 'Alta', 4 => 'Critica'][(int) $segnalazione->triage_suggerito['livello_priorita']] ?? $segnalazione->triage_suggerito['livello_priorita'] }}</li>
                        @endif
                    </ul>
                    <form method="POST" action="{{ route('segnalazioni.applica-triage', $segnalazione) }}">
                        @csrf
                        <x-pa.btn type="submit" size="sm">Applica</x-pa.btn>
                    </form>
                </x-pa.card>
            @endif

            <x-pa.card titolo="Attività">
                @include('segnalazioni.scheda._composer')
                @include('segnalazioni.scheda._attivita')
            </x-pa.card>
        </div>

        <div class="flex flex-col gap-5 lg:col-start-3 lg:row-start-2">
            <x-pa.card titolo="Dove">
                @include('segnalazioni.scheda._dove', ['conMappa' => true])
            </x-pa.card>

            <x-pa.card titolo="Dettagli">
                <dl class="m-0 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-sm">
                    <dt class="text-[var(--slate-500)]">Tipologia</dt>
                    <dd class="m-0">{{ $segnalazione->tipologia?->descrizione ?? '—' }}@if($segnalazione->tipologia?->gruppo) <span class="text-[var(--slate-500)]">· {{ $segnalazione->tipologia->gruppo->descrizione }}</span>@endif</dd>
                    @if($segnalazione->specializzazione)
                        <dt class="text-[var(--slate-500)]">Specializzazione</dt><dd class="m-0">{{ $segnalazione->specializzazione->descrizione }}</dd>
                    @endif
                    <dt class="text-[var(--slate-500)]">Provenienza</dt><dd class="m-0">{{ $segnalazione->provenienza?->descrizione ?? '—' }}</dd>
                    @if($segnalazione->utente)
                        <dt class="text-[var(--slate-500)]">Registrata da</dt><dd class="m-0">{{ $segnalazione->utente->name }}</dd>
                    @endif
                    <dt class="text-[var(--slate-500)]">Aperta il</dt><dd class="m-0">{{ $segnalazione->data_segnalazione?->format('d/m/Y H:i') }}</dd>
                    @if($segnalazione->data_scadenza_sla)
                        <dt class="text-[var(--slate-500)]">Scadenza</dt><dd class="m-0">{{ \Illuminate\Support\Carbon::parse($segnalazione->data_scadenza_sla)->format('d/m/Y H:i') }}</dd>
                    @endif
                    @if($segnalazione->data_chiusura)
                        <dt class="text-[var(--slate-500)]">Chiusa il</dt><dd class="m-0">{{ \Illuminate\Support\Carbon::parse($segnalazione->data_chiusura)->format('d/m/Y H:i') }}</dd>
                    @endif
                    @if($segnalazione->importo_preventivo > 0)
                        <dt class="text-[var(--slate-500)]">Preventivo</dt><dd class="m-0">€ {{ number_format((float) $segnalazione->importo_preventivo, 2, ',', '.') }}</dd>
                    @endif
                    @if($segnalazione->importo_liquidato > 0)
                        <dt class="text-[var(--slate-500)]">Liquidato</dt><dd class="m-0">€ {{ number_format((float) $segnalazione->importo_liquidato, 2, ',', '.') }}</dd>
                    @endif
                </dl>
            </x-pa.card>

            @include('segnalazioni.scheda._collegate')
        </div>
    </div>
</div>

{{-- Pannelli laterali: solo quelli che l'utente può usare. --}}
@if($disponibile('assegna_operatore') || $disponibile('assegna_impresa'))
    @include('segnalazioni.scheda.pannelli.assegna')
@endif
@if($disponibile('chiudi')) @include('segnalazioni.scheda.pannelli.chiudi') @endif
@if($disponibile('sospendi')) @include('segnalazioni.scheda.pannelli.sospendi') @endif
@if($disponibile('annulla')) @include('segnalazioni.scheda.pannelli.annulla') @endif
@if($disponibile('collega')) @include('segnalazioni.scheda.pannelli.collega') @endif
@if($disponibile('presenta_preventivo')) @include('segnalazioni.scheda.pannelli.preventivo') @endif
@can('modificaPriorita', $segnalazione) @include('segnalazioni.scheda.pannelli.priorita') @endcan
@can('sollecita', $segnalazione) @include('segnalazioni.scheda.pannelli.sollecita') @endcan
@can('accorpa', $segnalazione) @include('segnalazioni.scheda.pannelli.accorpa') @endcan
@foreach($generiche as $azione)
    @include('segnalazioni.scheda.pannelli.generica', ['azione' => $azione])
@endforeach
