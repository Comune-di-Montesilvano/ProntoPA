{{-- Azioni multiple: barra in basso con ≥1 riga selezionata e pannelli (dentro lo scope x-data con "scelte"). --}}
@php
    $appaltiMulti = $appaltiValidi->map(fn ($a) => [
        'id' => $a->id_appalto,
        'ditta' => $a->impresa?->ragione_sociale ?? '—',
        'descrizione' => $a->descrizione,
        // null = copre tutte le tipologie
        'tipologie' => $a->tutte_tipologie ? null : $a->tipologie->pluck('id_tipologia_segnalazione')->map(fn ($id) => (int) $id)->values(),
    ])->values();
    $tipologiaDi = $segnalazioni->mapWithKeys(fn ($s) => [(string) $s->id_segnalazione => (int) $s->id_tipologia_segnalazione]);
    $ritorno = request()->getQueryString();
    $motivi = \App\Services\SegnalazioneWorkflowService::MOTIVI_SENZA_INTERVENTO;
@endphp

<div x-show="scelte.length" x-cloak class="pa-barra-multipla" role="region" aria-label="Azioni sulle segnalazioni selezionate">
    <span class="text-sm font-semibold"><span x-text="scelte.length"></span> selezionate</span>
    <div class="flex flex-wrap gap-2">
        <x-pa.btn size="sm" variant="primary" x-on:click="$dispatch('apri-pannello', 'multi-assegna')">Assegna…</x-pa.btn>
        <x-pa.btn size="sm" x-on:click="$dispatch('apri-pannello', 'multi-chiudi')">Chiudi</x-pa.btn>
        <x-pa.btn size="sm" x-on:click="$dispatch('apri-pannello', 'multi-senza-intervento')">Chiudi senza intervento</x-pa.btn>
        <a class="pa-btn pa-btn-outline pa-btn-sm" target="_blank" rel="noopener"
           :href="@js(route('gestione.stampa-schede')) + '?' + scelte.map(id => 'ids[]=' + id).join('&')">Stampa schede</a>
        <x-pa.btn size="sm" x-on:click="scelte = []">Deseleziona</x-pa.btn>
    </div>
</div>

@php
    $intestazione = function (string $nome) use ($ritorno) {
        return csrf_field()
            .'<input type="hidden" name="operazione" value="'.e($nome).'">'
            .'<input type="hidden" name="ritorno" value="'.e((string) $ritorno).'">';
    };
@endphp

<x-pa.drawer name="multi-assegna" title="Assegna le segnalazioni selezionate">
    <form method="POST" action="{{ route('gestione.azioni-multiple') }}" class="flex flex-1 flex-col"
          x-data="{ tipo: 'operaio', cerca: '', appalto: 0, appalti: @js($appaltiMulti), tipologiaDi: @js($tipologiaDi),
                    copre(a) { return a.tipologie === null || this.scelte.every(id => a.tipologie.includes(this.tipologiaDi[id])); },
                    get filtrati() { const q = this.cerca.toLowerCase(); return this.appalti.filter(a => !q || (a.ditta + ' ' + a.descrizione).toLowerCase().includes(q)); } }">
        {!! $intestazione('assegna') !!}
        <template x-for="id in scelte" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
        <input type="hidden" name="tipo" :value="tipo">
        <div class="flex flex-1 flex-col gap-5 px-6 py-5">
            <p class="m-0 text-sm text-[var(--slate-600)]"><span x-text="scelte.length"></span> segnalazioni. Quelle che non si possono assegnare vengono saltate.</p>
            <fieldset class="m-0 flex flex-wrap gap-2 border-0 p-0">
                <legend class="pa-field-label mb-2">A chi</legend>
                @foreach(['operaio' => 'Operaio', 'squadra' => 'Squadra', 'ditta' => 'Ditta', 'me' => 'A me'] as $valore => $etichetta)
                    @continue($valore === 'squadra' && $squadre->isEmpty())
                    <label class="pa-btn pa-btn-outline pa-btn-sm cursor-pointer" :class="tipo === '{{ $valore }}' && 'pa-btn-secondary'">
                        <input type="radio" class="sr-only" value="{{ $valore }}" x-model="tipo"> {{ $etichetta }}
                    </label>
                @endforeach
            </fieldset>
            <div class="pa-field" x-show="tipo === 'operaio'">
                <label for="multi-operatore" class="pa-field-label">Operatore</label>
                <select id="multi-operatore" name="id_operatore" class="pa-select" :disabled="tipo !== 'operaio'" :required="tipo === 'operaio'">
                    <option value="">Scegli…</option>
                    @foreach($operatoriAssegnabili as $op)
                        <option value="{{ $op->id }}">{{ $op->name }}</option>
                    @endforeach
                </select>
            </div>
            @if($squadre->isNotEmpty())
                <div class="pa-field" x-show="tipo === 'squadra'" x-cloak>
                    <label for="multi-squadra" class="pa-field-label">Squadra</label>
                    <select id="multi-squadra" name="id_squadra" class="pa-select" :disabled="tipo !== 'squadra'" :required="tipo === 'squadra'">
                        <option value="">Scegli…</option>
                        @foreach($squadre as $sq)
                            <option value="{{ $sq->id_squadra }}">{{ $sq->nome }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="flex flex-col gap-2" x-show="tipo === 'ditta'" x-cloak>
                <label for="multi-cerca" class="pa-field-label">Appalto</label>
                <input id="multi-cerca" type="search" class="pa-input" placeholder="Cerca ditta o appalto…" x-model="cerca">
                <input type="hidden" name="id_appalto" :value="appalto || ''" :disabled="tipo !== 'ditta'">
                <ul class="m-0 max-h-60 overflow-y-auto rounded-md border border-[var(--slate-200)] p-0 list-none">
                    <template x-for="a in filtrati.filter(a => copre(a))" :key="a.id">
                        <li>
                            <button type="button" class="block w-full px-3 py-2 text-left text-sm hover:bg-[var(--slate-50)]"
                                    :class="appalto === a.id && 'bg-[var(--ente-primary-50)]'" x-on:click="appalto = a.id">
                                <strong x-text="a.ditta"></strong>
                                <span class="block text-[var(--slate-500)]" x-text="a.descrizione"></span>
                            </button>
                        </li>
                    </template>
                    <li x-show="filtrati.some(a => copre(a)) && filtrati.some(a => !copre(a))"
                        class="border-t border-[var(--slate-200)] bg-[var(--slate-50)] px-3 py-1 text-xs font-semibold uppercase text-[var(--slate-500)]">Altre ditte</li>
                    <template x-for="a in filtrati.filter(a => !copre(a))" :key="a.id">
                        <li>
                            <button type="button" class="block w-full px-3 py-2 text-left text-sm hover:bg-[var(--slate-50)]"
                                    :class="appalto === a.id && 'bg-[var(--ente-primary-50)]'" x-on:click="appalto = a.id">
                                <strong x-text="a.ditta"></strong>
                                <span class="block text-[var(--slate-500)]" x-text="a.descrizione"></span>
                            </button>
                        </li>
                    </template>
                    <li x-show="filtrati.length === 0" class="px-3 py-2 text-sm text-[var(--slate-500)]">Nessun appalto valido trovato.</li>
                </ul>
            </div>
            <div class="pa-field">
                <label for="multi-scadenza" class="pa-field-label">Scadenza (facoltativa)</label>
                <input id="multi-scadenza" type="date" name="data_scadenza" min="{{ now()->format('Y-m-d') }}" class="pa-input">
                <p class="pa-field-help">Vuota: ognuna tiene la sua.</p>
            </div>
            <div class="pa-field" x-show="tipo !== 'me'">
                <label for="multi-nota" class="pa-field-label">Nota per chi riceve il lavoro (facoltativa)</label>
                <textarea id="multi-nota" name="nota" rows="3" maxlength="2000" class="pa-textarea"></textarea>
            </div>
        </div>
        <footer class="flex justify-end gap-2 border-t border-[var(--slate-200)] px-6 py-4">
            <x-pa.btn x-on:click="$dispatch('apri-pannello', '')">Annulla</x-pa.btn>
            <x-pa.btn type="submit" variant="primary" ::disabled="tipo === 'ditta' && !appalto">Assegna</x-pa.btn>
        </footer>
    </form>
</x-pa.drawer>

<x-pa.drawer name="multi-chiudi" title="Chiudi le segnalazioni selezionate">
    <form method="POST" action="{{ route('gestione.azioni-multiple') }}" class="flex flex-1 flex-col">
        {!! $intestazione('chiudi') !!}
        <template x-for="id in scelte" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
        <div class="flex flex-1 flex-col gap-4 px-6 py-5">
            <p class="m-0 text-sm text-[var(--slate-600)]">Si chiudono come lavoro fatto. Le Nuove vanno chiuse "senza intervento": verranno saltate.</p>
            <div class="pa-field">
                <label for="multi-chiudi-nota" class="pa-field-label">Cosa è stato fatto (facoltativo)</label>
                <textarea id="multi-chiudi-nota" name="nota" rows="3" maxlength="2000" class="pa-textarea"></textarea>
            </div>
        </div>
        <footer class="flex justify-end gap-2 border-t border-[var(--slate-200)] px-6 py-4">
            <x-pa.btn x-on:click="$dispatch('apri-pannello', '')">Annulla</x-pa.btn>
            <x-pa.btn type="submit" variant="primary">Chiudi <span x-text="scelte.length"></span></x-pa.btn>
        </footer>
    </form>
</x-pa.drawer>

<x-pa.drawer name="multi-senza-intervento" title="Chiudi senza intervento">
    <form method="POST" action="{{ route('gestione.azioni-multiple') }}" class="flex flex-1 flex-col" x-data="{ motivo: null }">
        {!! $intestazione('chiudi_senza_intervento') !!}
        <template x-for="id in scelte" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
        <div class="flex flex-1 flex-col gap-4 px-6 py-5">
            <fieldset class="m-0 flex flex-col gap-2 border-0 p-0">
                <legend class="pa-field-label mb-1">Perché</legend>
                @foreach($motivi as $valore => $etichetta)
                    <label class="flex items-center gap-2 text-sm">
                        <input type="radio" name="motivo" value="{{ $valore }}" x-model="motivo" required> {{ $etichetta }}
                    </label>
                @endforeach
            </fieldset>
            <div class="pa-field">
                <label for="multi-annulla-nota" class="pa-field-label">
                    <span x-show="motivo === 'altro'">Spiega il motivo</span>
                    <span x-show="motivo !== 'altro'">Nota (facoltativa)</span>
                </label>
                <textarea id="multi-annulla-nota" name="nota" rows="3" maxlength="2000" class="pa-textarea" :required="motivo === 'altro'"></textarea>
                <p class="pa-field-help">Il motivo lo vede anche chi ha segnalato.</p>
            </div>
        </div>
        <footer class="flex justify-end gap-2 border-t border-[var(--slate-200)] px-6 py-4">
            <x-pa.btn x-on:click="$dispatch('apri-pannello', '')">Annulla</x-pa.btn>
            <x-pa.btn type="submit" variant="danger">Chiudi senza intervento</x-pa.btn>
        </footer>
    </form>
</x-pa.drawer>
