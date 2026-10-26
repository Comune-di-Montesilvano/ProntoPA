@php
    $viste = [
        'nuove' => 'Da smistare', 'aperte' => 'Aperte', 'in_carico' => 'In carico',
        'in_gestione' => 'In lavorazione', 'evidenza' => 'In evidenza', 'chiuse' => 'Chiuse',
    ];
    $filtriRapidi = [
        'scadute' => 'Tempo massimo superato', 'entro24h' => 'Scadono entro 24 h',
        'sollecitate' => 'Sollecitate', 'ricorrenti' => 'Ricorrenti',
    ];
    $priorita = [1 => 'Bassa', 2 => 'Media', 3 => 'Alta', 4 => 'Critica'];
    $senza = fn (string ...$chiavi) => route('gestione.dashboard', array_diff_key($filtri, array_flip($chiavi)));

    // Etichette dei filtri attivi, ognuna con il link che la toglie
    $attivi = [];
    if ($q !== '') { $attivi[] = ['Cerca: '.$q, $senza('q')]; }
    if ($filtro) { $attivi[] = [$filtriRapidi[$filtro], $senza('filtro')]; }
    if ($idTipologia) { $attivi[] = ['Tipologia: '.($tipologie->firstWhere('id_tipologia_segnalazione', $idTipologia)?->descrizione ?? $idTipologia), $senza('id_tipologia')]; }
    if ($idProvenienza) { $attivi[] = ['Provenienza: '.($provenienze->firstWhere('id_provenienza', $idProvenienza)?->descrizione ?? $idProvenienza), $senza('id_provenienza')]; }
    if ($idOperatore) { $attivi[] = ['Operatore: '.($operatori->firstWhere('id', $idOperatore)?->name ?? $idOperatore), $senza('id_operatore')]; }
    if ($livelloPriorita) { $attivi[] = ['Priorità: '.($priorita[(int) $livelloPriorita] ?? $livelloPriorita), $senza('livello_priorita')]; }
    if ($idSpecializzazione) { $attivi[] = ['Specializzazione: '.($specializzazioni->firstWhere('id_specializzazione', $idSpecializzazione)?->descrizione ?? $idSpecializzazione), $senza('id_specializzazione')]; }
    if ($dataDa) { $attivi[] = ['Dal '.\Illuminate\Support\Carbon::parse($dataDa)->format('d/m/Y'), $senza('data_da')]; }
    if ($dataA) { $attivi[] = ['Al '.\Illuminate\Support\Carbon::parse($dataA)->format('d/m/Y'), $senza('data_a')]; }
    $pannelloAperto = $idTipologia || $idProvenienza || $idOperatore || $livelloPriorita || $idSpecializzazione || $dataDa || $dataA;
@endphp

<x-app-layout>
    <x-slot name="header">Segnalazioni</x-slot>
    <x-slot name="actions">
        <div x-data="{ open: false }" class="relative" @keydown.escape.window="open = false">
            <x-pa.btn x-on:click="open = !open" ::aria-expanded="open">
                <span class="sm:hidden" aria-hidden="true"><i class="fas fa-print"></i></span><span class="sr-only sm:hidden">Stampa ed esporta</span><span class="hidden sm:inline">Stampa ed esporta</span>
                <i class="fas fa-chevron-down text-xs" aria-hidden="true"></i>
            </x-pa.btn>
            <div x-show="open" x-cloak @click.outside="open = false"
                 class="absolute right-0 z-30 mt-1 w-60 rounded-md border border-[var(--slate-200)] bg-white py-1 shadow-lg">
                <a href="{{ route('gestione.stampa', $filtri) }}" target="_blank" class="block px-4 py-2 text-sm text-[var(--slate-700)] hover:bg-[var(--slate-50)]">Stampa lista</a>
                <a href="{{ route('gestione.stampa-schede', $filtri) }}" target="_blank" title="Tutte le schede dell'elenco filtrato, una per pagina"
                   class="block px-4 py-2 text-sm text-[var(--slate-700)] hover:bg-[var(--slate-50)]">Stampa schede ({{ $segnalazioni->total() }})</a>
                <a href="{{ route('gestione.export-csv', $filtri) }}" class="block px-4 py-2 text-sm text-[var(--slate-700)] hover:bg-[var(--slate-50)]">Esporta CSV</a>
                <a href="{{ route('gestione.reports.mensile', ['mese' => now()->month, 'anno' => now()->year]) }}" target="_blank"
                   class="block px-4 py-2 text-sm text-[var(--slate-700)] hover:bg-[var(--slate-50)]">Report del mese</a>
            </div>
        </div>
        <x-pa.btn variant="primary" :href="route('segnalazioni.create')">
            <i class="fas fa-plus" aria-hidden="true"></i> <span class="sm:hidden">Nuova</span><span class="hidden sm:inline">Nuova segnalazione</span>
        </x-pa.btn>
    </x-slot>

    <div class="flex flex-col gap-4">
        <p class="m-0 text-[var(--slate-600)]">
            {{ $conteggi['aperte'] }} {{ $conteggi['aperte'] === 1 ? 'aperta' : 'aperte' }},
            di cui {{ $conteggi['nuove'] }} da smistare
        </p>

        {{-- Da guardare: riquadri cliccabili = filtri, nascosti se a zero --}}
        @php
            $daGuardare = array_filter([
                ['n' => $conteggi['scadute'], 'testo' => 'tempo massimo superato', 'tono' => 'rose',
                 'url' => route('gestione.dashboard', ['tab' => 'aperte', 'filtro' => 'scadute']), 'attivo' => $filtro === 'scadute'],
                ['n' => $conteggi['entro24h'], 'testo' => 'scadono entro 24 h', 'tono' => 'amber',
                 'url' => route('gestione.dashboard', ['tab' => 'aperte', 'filtro' => 'entro24h']), 'attivo' => $filtro === 'entro24h'],
                ['n' => $conteggi['nuove'], 'testo' => 'nuove da smistare', 'tono' => 'primary',
                 'url' => route('gestione.dashboard', ['tab' => 'nuove']), 'attivo' => $tab === 'nuove' && ! $filtro],
            ], fn ($r) => $r['n'] > 0);
        @endphp
        @if($daGuardare)
            <section aria-label="Da guardare" class="grid gap-3 sm:grid-cols-3">
                @foreach($daGuardare as $r)
                    <a href="{{ $r['url'] }}"
                       class="pa-card flex items-baseline gap-2 px-4 py-3 no-underline hover:shadow-md {{ $r['attivo'] ? 'ring-2 ring-[var(--ente-primary)]' : '' }}">
                        <span class="text-2xl font-bold pa-testo-{{ $r['tono'] }}">{{ $r['n'] }}</span>
                        <span class="text-sm text-[var(--slate-700)]">{{ $r['testo'] }}</span>
                    </a>
                @endforeach
            </section>
        @endif

        {{-- Viste con conteggio: conservano ricerca, filtri e ordinamento --}}
        <nav aria-label="Viste" class="flex gap-1 overflow-x-auto border-b border-[var(--slate-200)] pb-2">
            @foreach($viste as $chiave => $etichetta)
                <a href="{{ route('gestione.dashboard', ['tab' => $chiave] + $filtri) }}"
                   class="pa-vista {{ $tab === $chiave ? 'attiva' : '' }}" @if($tab === $chiave) aria-current="page" @endif>
                    {{ $etichetta }} <span class="pa-vista-conteggio">{{ $conteggi[$chiave] }}</span>
                </a>
            @endforeach
        </nav>

        {{-- Ricerca, ordinamento e pannello filtri --}}
        <form method="GET" action="{{ route('gestione.dashboard') }}" class="flex flex-col gap-3">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <div class="flex flex-wrap items-center gap-2">
                <label for="filtro_q" class="sr-only">Cerca</label>
                <input type="search" name="q" id="filtro_q" value="{{ $q }}" class="pa-input pa-input-auto min-w-[220px] flex-1"
                       placeholder="Cerca per testo, segnalante o numero…">
                <label for="filtro_ordine" class="sr-only">Ordina</label>
                <select name="ordine" id="filtro_ordine" class="pa-input pa-input-auto" onchange="this.form.submit()" @disabled($tab === 'chiuse')>
                    <option value="urgenti" @selected($ordine === 'urgenti')>Più urgenti</option>
                    <option value="recenti" @selected($ordine === 'recenti')>Più recenti</option>
                    <option value="vecchie" @selected($ordine === 'vecchie')>Più vecchie</option>
                </select>
                <x-pa.btn type="submit" variant="primary">Cerca</x-pa.btn>
            </div>

            <details class="pa-card px-4 py-3" @if($pannelloAperto) open @endif>
                <summary class="cursor-pointer text-sm font-semibold text-[var(--slate-700)]">
                    <i class="fas fa-sliders-h mr-1" aria-hidden="true"></i> Filtri
                </summary>
                <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="pa-field">
                        <label for="filtro_filtro" class="pa-field-label">Mostra solo</label>
                        <select name="filtro" id="filtro_filtro" class="pa-input">
                            <option value="">Tutte</option>
                            @foreach($filtriRapidi as $valore => $etichetta)
                                <option value="{{ $valore }}" @selected($filtro === $valore)>{{ $etichetta }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="pa-field">
                        <label for="filtro_id_tipologia" class="pa-field-label">Tipologia</label>
                        <select name="id_tipologia" id="filtro_id_tipologia" class="pa-input">
                            <option value="">Tutte</option>
                            @foreach($tipologie as $t)
                                <option value="{{ $t->id_tipologia_segnalazione }}" @selected($idTipologia == $t->id_tipologia_segnalazione)>{{ $t->descrizione }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="pa-field">
                        <label for="filtro_id_provenienza" class="pa-field-label">Provenienza</label>
                        <select name="id_provenienza" id="filtro_id_provenienza" class="pa-input">
                            <option value="">Tutte</option>
                            @foreach($provenienze as $p)
                                <option value="{{ $p->id_provenienza }}" @selected($idProvenienza == $p->id_provenienza)>{{ $p->descrizione }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="pa-field">
                        <label for="filtro_id_operatore" class="pa-field-label">Operatore</label>
                        <select name="id_operatore" id="filtro_id_operatore" class="pa-input">
                            <option value="">Tutti</option>
                            @foreach($operatori as $op)
                                <option value="{{ $op->id }}" @selected($idOperatore == $op->id)>{{ $op->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="pa-field">
                        <label for="filtro_livello_priorita" class="pa-field-label">Priorità</label>
                        <select name="livello_priorita" id="filtro_livello_priorita" class="pa-input">
                            <option value="">Tutte</option>
                            @foreach($priorita as $valore => $etichetta)
                                <option value="{{ $valore }}" @selected($livelloPriorita == $valore)>{{ $etichetta }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if($specializzazioni->isNotEmpty())
                        <div class="pa-field">
                            <label for="filtro_id_specializzazione" class="pa-field-label">Specializzazione</label>
                            <select name="id_specializzazione" id="filtro_id_specializzazione" class="pa-input">
                                <option value="">Tutte</option>
                                @foreach($specializzazioni as $sp)
                                    <option value="{{ $sp->id_specializzazione }}" @selected($idSpecializzazione == $sp->id_specializzazione)>{{ $sp->descrizione }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div class="pa-field">
                        <label for="filtro_data_da" class="pa-field-label">Segnalate dal</label>
                        <input type="date" name="data_da" id="filtro_data_da" value="{{ $dataDa }}" class="pa-input">
                    </div>
                    <div class="pa-field">
                        <label for="filtro_data_a" class="pa-field-label">Al</label>
                        <input type="date" name="data_a" id="filtro_data_a" value="{{ $dataA }}" class="pa-input">
                    </div>
                </div>
                <div class="mt-3 flex justify-end">
                    <x-pa.btn type="submit" variant="primary" size="sm">Applica filtri</x-pa.btn>
                </div>
            </details>
        </form>

        @if($attivi)
            <div class="flex flex-wrap items-center gap-2" aria-label="Filtri attivi">
                @foreach($attivi as [$etichetta, $url])
                    <a href="{{ $url }}" class="pa-pill pa-pill-primary no-underline" title="Togli questo filtro">
                        {{ $etichetta }} <i class="fas fa-times" aria-hidden="true"></i><span class="sr-only">(togli)</span>
                    </a>
                @endforeach
                <a href="{{ route('gestione.dashboard', ['tab' => $tab]) }}" class="text-sm font-semibold text-[var(--ente-primary)]">Azzera</a>
            </div>
        @endif

        <div class="pa-card overflow-hidden">
            @if($segnalazioni->isEmpty())
                <p class="m-0 p-10 text-center text-sm text-[var(--slate-500)]">Nessuna segnalazione in questa vista.</p>
            @else
                <table class="pa-elenco">
                    <thead>
                        <tr>
                            <th scope="col">Priorità</th>
                            <th scope="col">Segnalazione</th>
                            <th scope="col">Stato</th>
                            <th scope="col">Assegnata a</th>
                            <th scope="col">Scadenza</th>
                            <th scope="col"><span class="sr-only">Azioni</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($segnalazioni as $s)
                            @include('segnalazioni._riga', [
                                'colonne' => ['priorita', 'segnalazione', 'stato', 'assegnata', 'scadenza', 'azioni'],
                                'rapide' => $azioniRapidePerSegnalazione[$s->id_segnalazione] ?? collect(),
                            ])
                        @endforeach
                    </tbody>
                </table>
                @if($segnalazioni->hasPages())
                    <div class="border-t border-[var(--slate-100)] px-4 py-3">
                        {{ $segnalazioni->withQueryString()->links() }}
                    </div>
                @endif
            @endif
        </div>
    </div>
</x-app-layout>
