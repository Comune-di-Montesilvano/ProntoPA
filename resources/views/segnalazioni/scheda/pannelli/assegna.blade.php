{{-- Assegna / riassegna: a un operatore, a una squadra o a una ditta (appalto valido). --}}
@php
    $azOperatore = $azioniDisponibili->firstWhere('codice', 'assegna_operatore');
    $azImpresa = $azioniDisponibili->firstWhere('codice', 'assegna_impresa');
    $iniziale = old('_tipo', $azImpresa && ! $azOperatore ? 'ditta' : 'operaio');
    $appalti = $appaltiValidi->map(fn ($a) => [
        'id' => $a->id_appalto,
        'ditta' => $a->impresa?->ragione_sociale ?? '—',
        'descrizione' => $a->descrizione,
        'email' => (bool) $a->impresa?->email,
    ])->values();
@endphp
<x-pa.drawer name="assegna" title="Assegna" :sottotitolo="'#'.$segnalazione->id_segnalazione" :aperto="old('_pannello') === 'assegna'">
    <form method="POST" action="{{ route('segnalazioni.azione', $segnalazione->id_segnalazione) }}" class="flex flex-1 flex-col"
          x-data="{ tipo: @js($iniziale), cerca: '', appalto: @js((int) old('id_appalto')), appalti: @js($appalti),
                    get filtrati() { const q = this.cerca.toLowerCase(); return this.appalti.filter(a => !q || (a.ditta + ' ' + a.descrizione).toLowerCase().includes(q)); },
                    get scelto() { return this.appalti.find(a => a.id === this.appalto); } }">
        @csrf
        <input type="hidden" name="_pannello" value="assegna">
        <input type="hidden" name="_tipo" :value="tipo">
        <input type="hidden" name="id_azione" :value="tipo === 'ditta' ? {{ (int) $azImpresa?->id_azione }} : {{ (int) $azOperatore?->id_azione }}">

        <div class="flex flex-1 flex-col gap-5 px-6 py-5">
            <fieldset class="m-0 flex flex-wrap gap-2 border-0 p-0">
                <legend class="pa-field-label mb-2">A chi</legend>
                @if($azOperatore)
                    <label class="pa-btn pa-btn-outline pa-btn-sm cursor-pointer" :class="tipo === 'operaio' && 'pa-btn-secondary'">
                        <input type="radio" class="sr-only" value="operaio" x-model="tipo"> Operaio
                    </label>
                    @if($squadre->isNotEmpty())
                        <label class="pa-btn pa-btn-outline pa-btn-sm cursor-pointer" :class="tipo === 'squadra' && 'pa-btn-secondary'">
                            <input type="radio" class="sr-only" value="squadra" x-model="tipo"> Squadra
                        </label>
                    @endif
                @endif
                @if($azImpresa)
                    <label class="pa-btn pa-btn-outline pa-btn-sm cursor-pointer" :class="tipo === 'ditta' && 'pa-btn-secondary'">
                        <input type="radio" class="sr-only" value="ditta" x-model="tipo"> Ditta
                    </label>
                @endif
            </fieldset>

            @if($azOperatore)
                <div class="pa-field" x-show="tipo === 'operaio'">
                    <label for="assegna-operatore" class="pa-field-label">Operatore</label>
                    <select id="assegna-operatore" name="id_operatore" class="pa-select" :disabled="tipo !== 'operaio'" :required="tipo === 'operaio'">
                        <option value="">Scegli…</option>
                        @foreach($operatori as $op)
                            <option value="{{ $op->id }}" @selected((int) old('id_operatore') === $op->id)>{{ $op->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('id_operatore')" />
                </div>
                @if($squadre->isNotEmpty())
                    <div class="pa-field" x-show="tipo === 'squadra'" x-cloak>
                        <label for="assegna-squadra" class="pa-field-label">Squadra</label>
                        <select id="assegna-squadra" name="id_squadra" class="pa-select" :disabled="tipo !== 'squadra'" :required="tipo === 'squadra'">
                            <option value="">Scegli…</option>
                            @foreach($squadre as $sq)
                                <option value="{{ $sq->id_squadra }}">{{ $sq->nome }}</option>
                            @endforeach
                        </select>
                        <p class="pa-field-help">L'avviso arriva al caposquadra, che affida il lavoro.</p>
                    </div>
                @endif
            @endif

            @if($azImpresa)
                <div class="flex flex-col gap-2" x-show="tipo === 'ditta'" x-cloak>
                    <label for="assegna-cerca" class="pa-field-label">Appalto</label>
                    <input id="assegna-cerca" type="search" class="pa-input" placeholder="Cerca ditta o appalto…" x-model="cerca">
                    <input type="hidden" name="id_appalto" :value="appalto || ''" :disabled="tipo !== 'ditta'">
                    <ul class="m-0 max-h-72 overflow-y-auto rounded-md border border-[var(--slate-200)] p-0 list-none">
                        <template x-for="a in filtrati" :key="a.id">
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
                    <p class="pa-field-help" x-show="scelto">
                        Avviseremo per email le persone delegate di <strong x-text="scelto?.ditta"></strong><span x-show="scelto?.email"> e l'indirizzo aziendale</span>.
                    </p>
                    <x-input-error :messages="$errors->get('id_appalto')" />
                </div>
            @endif

            @can('modificaPriorita', $segnalazione)
                @php $prioritaIniziale = (int) (old('_pannello') === 'assegna' ? old('livello_priorita', $segnalazione->livello_priorita ?: 2) : ($segnalazione->livello_priorita ?: 2)); @endphp
                <fieldset class="m-0 flex flex-col gap-2 border-0 p-0">
                    <legend class="pa-field-label mb-1">Priorità</legend>
                    <div class="flex flex-wrap gap-3">
                        @foreach([1 => 'Bassa', 2 => 'Media', 3 => 'Alta', 4 => 'Critica'] as $val => $label)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="radio" name="livello_priorita" value="{{ $val }}" @checked($prioritaIniziale === $val)>
                                <x-pa.prio :livello="$val" />
                            </label>
                        @endforeach
                    </div>
                    <p class="pa-field-help">Cambiandola si ricalcola la scadenza.</p>
                    <x-input-error :messages="$errors->get('livello_priorita')" />
                </fieldset>
            @endcan

            <div class="pa-field">
                <label for="assegna-nota" class="pa-field-label">Nota per chi riceve il lavoro (facoltativa)</label>
                <textarea id="assegna-nota" name="nota" rows="3" maxlength="2000" class="pa-textarea">{{ old('_pannello') === 'assegna' ? old('nota') : '' }}</textarea>
            </div>
            <x-input-error :messages="$errors->get('azione')" />
        </div>

        <footer class="flex justify-end gap-2 border-t border-[var(--slate-200)] px-6 py-4">
            <x-pa.btn x-on:click="$dispatch('apri-pannello', '')">Annulla</x-pa.btn>
            <x-pa.btn type="submit" variant="primary" ::disabled="tipo === 'ditta' && !appalto">Assegna</x-pa.btn>
        </footer>
    </form>
</x-pa.drawer>
