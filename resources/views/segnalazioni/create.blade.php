<x-app-layout>
    <x-slot name="header">Nuova segnalazione</x-slot>
    <x-slot name="actions">
        <a href="{{ url()->previous() }}"
           class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 transition">
            Annulla
        </a>
    </x-slot>

    @php
        // Prima l'istituto, poi la sede: scelta già fatta quando ce n'è una sola.
        $perIstituto = $plessi->groupBy('id_istituto');
        $sedeScelta = old('id_plesso', $plessi->count() === 1 ? $plessi->first()->id_plesso : null);
        $istitutoScelto = $sedeScelta
            ? $plessi->firstWhere('id_plesso', (int) $sedeScelta)?->id_istituto
            : ($perIstituto->count() === 1 ? $perIstituto->keys()->first() : null);
        if (! $sedeScelta && $istitutoScelto && $perIstituto[$istitutoScelto]->count() === 1) {
            $sedeScelta = $perIstituto[$istitutoScelto]->first()->id_plesso;
        }
        $indirizzi = $plessi->mapWithKeys(fn ($p) => [(string) $p->id_plesso => (string) $p->indirizzo]);
        $sediUniche = $perIstituto->filter(fn ($sedi) => $sedi->count() === 1)
            ->mapWithKeys(fn ($sedi, $id) => [(string) $id => (string) $sedi->first()->id_plesso]);
        $allegatiMaxSizeMb     = (int) \App\Models\Impostazione::get('allegati_max_size_mb', 10);
        $allegatiMaxPerRequest = (int) \App\Models\Impostazione::get('allegati_max_per_request', 5);
        $allegatiMime          = \App\Models\Impostazione::get('allegati_mime_consentiti', 'image/jpeg,image/png');
    @endphp

    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
        <form method="POST" action="{{ route('segnalazioni.store') }}" class="p-6 space-y-6"
              enctype="multipart/form-data"
              x-data="{
                  istituto: @js($istitutoScelto ? (string) $istitutoScelto : ''),
                  sede: @js($sedeScelta ? (string) $sedeScelta : ''),
                  indirizzi: @js($indirizzi),
                  sediUniche: @js($sediUniche),
                  tipologiaId: @js(old('id_tipologia_segnalazione') ? (int) old('id_tipologia_segnalazione') : null),
                  simili: [],
                  ignora: false,
                  esistente: null,
                  diversa: @js((bool) old('diversa_da')),
                  indirizzo() { return this.indirizzi[this.sede] ?? '' },
                  scegliIstituto() { this.sede = this.sediUniche[this.istituto] ?? '' },
              }"
              x-init="$watch('tipologiaId', () => { ignora = false; diversa = false; controllaSimili($data) });
                      $watch('sede', () => { ignora = false; diversa = false; controllaSimili($data) });
                      controllaSimili($data)">
            @csrf

            {{-- 1. Istituto, poi sede --}}
            @if($plessi->isEmpty())
                <div>
                    <x-input-label value="Sede *" />
                    <p class="mt-1 rounded-md border border-yellow-300 bg-yellow-50 px-3 py-2 text-sm text-yellow-800">
                        Non hai sedi su cui segnalare. Le scuole segnalano per le sedi coperte dalle loro deleghe.
                    </p>
                </div>
            @else
                <div>
                    <x-input-label for="istituto" value="Istituto *" />
                    @if($perIstituto->count() === 1)
                        <p class="mt-1 font-medium text-gray-900">{{ $plessi->first()->istituto?->descrizione }}</p>
                    @else
                        <select id="istituto" x-model="istituto" @change="scegliIstituto()" required
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                            <option value="">— Scegli l'istituto —</option>
                            @foreach($perIstituto->sortBy(fn ($sedi) => $sedi->first()->istituto?->descrizione) as $idIstituto => $sedi)
                                <option value="{{ $idIstituto }}" @selected((string) $istitutoScelto === (string) $idIstituto)>{{ $sedi->first()->istituto?->descrizione }}</option>
                            @endforeach
                        </select>
                    @endif
                </div>

                <div x-show="istituto" @if(! $istitutoScelto) style="display: none" @endif>
                    <x-input-label value="Sede *" />
                    {{-- Un campo per istituto: solo quello attivo viene inviato (i disabilitati no).
                         Sede unica: solo il nome, niente da scegliere. --}}
                    @foreach($perIstituto as $idIstituto => $sedi)
                        @php $attiva = (string) $istitutoScelto === (string) $idIstituto; @endphp
                        @if($sedi->count() === 1)
                            @php $unica = $sedi->first(); @endphp
                            <div x-show="istituto == '{{ $idIstituto }}'" @if(! $attiva) style="display: none" @endif>
                                <input type="hidden" name="id_plesso" value="{{ $unica->id_plesso }}" data-istituto="{{ $idIstituto }}"
                                       :disabled="istituto != '{{ $idIstituto }}'" @if(! $attiva) disabled @endif>
                                <p class="mt-1 font-medium text-gray-900">{{ $unica->nome }}@if($unica->ordineScuola()) · {{ $unica->ordineScuola() }}@endif</p>
                            </div>
                            @continue
                        @endif
                        <select name="id_plesso" data-istituto="{{ $idIstituto }}" required
                                x-model="sede" x-show="istituto == '{{ $idIstituto }}'" :disabled="istituto != '{{ $idIstituto }}'"
                                @if(! $attiva) disabled style="display: none" @endif
                                aria-label="Sede"
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 @error('id_plesso') border-red-500 @enderror">
                            <option value="">— Scegli la sede —</option>
                            @foreach($sedi->sortBy('nome') as $p)
                                <option value="{{ $p->id_plesso }}" @selected((string) $sedeScelta === (string) $p->id_plesso)>{{ $p->nome }}@if($p->ordineScuola()) · {{ $p->ordineScuola() }}@endif</option>
                            @endforeach
                        </select>
                    @endforeach
                    <p class="mt-1 text-sm text-gray-600" x-show="indirizzo()" x-text="indirizzo()"></p>
                </div>
            @endif
            <x-input-error :messages="$errors->get('id_plesso')" class="-mt-4" />

            {{-- 2. Tipologia --}}
            <div>
                <x-input-label value="Tipologia *" />
                <input type="hidden" name="id_tipologia_segnalazione" :value="tipologiaId">
                <div class="mt-2 grid grid-cols-2 sm:grid-cols-4 md:grid-cols-6 gap-2">
                    @foreach($tipologie as $t)
                        <button type="button"
                                @click="tipologiaId = {{ $t->id_tipologia_segnalazione }}"
                                :class="tipologiaId == {{ $t->id_tipologia_segnalazione }} ? 'pa-riquadro-scelto' : ''"
                                :aria-pressed="tipologiaId == {{ $t->id_tipologia_segnalazione }}"
                                class="pa-riquadro-tipologia pa-tono-{{ $t->tono() }}">
                            <span><i class="{{ $t->icona ?: 'fas fa-tag' }} text-lg" aria-hidden="true"></i></span>
                            <span class="text-xs font-semibold text-center leading-tight">{{ $t->nomeLeggibile() }}</span>
                        </button>
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('id_tipologia_segnalazione')" class="mt-1" />
            </div>

            {{-- 3a. Già aperta per stessa sede e tipologia (1.0.2): si scrive lì, salvo problema diverso. --}}
            <input type="hidden" name="diversa_da" :value="diversa && esistente ? esistente.id : ''">
            <div x-show="esistente && !diversa" style="display: none" class="pa-card pa-passo pa-passo-amber p-5 space-y-4" role="region" aria-labelledby="esistente-titolo">
                <div>
                    <p class="m-0 pa-sezione-titolo">Esiste già</p>
                    <h2 id="esistente-titolo" class="m-0 text-lg font-semibold text-[var(--ink)]">C'è già una segnalazione aperta per questo</h2>
                    <p class="m-0 mt-1 text-sm text-[var(--slate-700)]">Stessa sede e stessa tipologia. Scrivi lì: l'ufficio la sta già seguendo e riceve subito il tuo messaggio.</p>
                    <x-input-error :messages="$errors->get('esistente')" class="mt-2" />
                </div>
                <template x-if="esistente">
                    <div class="rounded-lg border border-[var(--slate-200)] bg-white p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2 text-sm text-[var(--slate-500)]">
                            <span><strong class="text-[var(--ink)]" x-text="'#' + esistente.id"></strong>
                                <span x-text="' · aperta ' + esistente.aperta_da + (esistente.tua ? ' da te' : ' da un'altra persona della tua sede')"></span></span>
                            <x-pa.pill tono="slate"><span x-text="esistente.stato"></span></x-pa.pill>
                        </div>
                        <p class="m-0 mt-1 font-medium text-[var(--ink)]" x-text="esistente.titolo"></p>
                        <template x-if="esistente.ultima_risposta">
                            <p class="m-0 mt-2 text-sm text-[var(--slate-700)]">
                                Ultima risposta dell'ufficio, <span x-text="esistente.ultima_risposta.quando"></span>:
                                «<span x-text="esistente.ultima_risposta.testo"></span>»
                            </p>
                        </template>
                    </div>
                </template>
                <div>
                    <label for="aggiornamento-testo" class="pa-field-label">Hai qualcosa da aggiungere? (facoltativo)</label>
                    <textarea id="aggiornamento-testo" name="testo" rows="3" maxlength="2000" class="pa-textarea" :disabled="!esistente || diversa"></textarea>
                </div>
                <div>
                    <label for="aggiornamento-foto" class="pa-field-label">Foto (facoltative)</label>
                    <x-pa.foto id="aggiornamento-foto" :mime="$allegatiMime" ::disabled="!esistente || diversa" />
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <button type="submit" formnovalidate class="pa-btn pa-btn-primary"
                            :formaction="esistente ? '{{ url('segnalazioni') }}/' + esistente.id + '/aggiornamento' : ''"
                            x-text="esistente ? 'Invia alla #' + esistente.id : 'Invia'"></button>
                    <button type="button" class="text-sm font-semibold text-[var(--ente-primary)] underline" @click="diversa = true">
                        È un problema diverso, apro una nuova segnalazione →
                    </button>
                </div>
            </div>
            <p x-show="esistente && diversa" style="display: none" class="m-0 rounded-md border border-[var(--slate-200)] bg-[var(--slate-50)] px-3 py-2 text-sm text-[var(--slate-700)]" role="status">
                Stai aprendo una segnalazione nuova: la <strong x-text="esistente ? '#' + esistente.id : ''"></strong> resta aperta.
            </p>

            <div x-show="!esistente || diversa" class="space-y-6">
            {{-- 3. Segnalazioni simili: stessa sede e tipologia --}}
            <template x-if="simili.length > 0 && !ignora">
                <div class="rounded-lg border border-yellow-400 bg-yellow-50 p-4">
                    <p class="m-0 font-semibold text-yellow-800">
                        <span x-text="simili.length"></span> segnalazioni già aperte per questa sede e tipologia
                    </p>
                    <ul class="mt-2 space-y-2">
                        <template x-for="s in simili" :key="s.id">
                            <li class="flex items-center justify-between gap-3 text-sm">
                                <span>
                                    <strong x-text="'#' + s.id"></strong>
                                    <template x-if="s.testo"><span x-text="' ' + s.testo"></span></template>
                                    <em class="text-gray-500" x-text="' (' + s.stato + ', ' + s.data + ')'"></em>
                                </span>
                                <button type="button" @click="aderisciSimile(s.id)"
                                        class="shrink-0 rounded bg-yellow-600 px-3 py-1 text-xs font-medium text-white hover:bg-yellow-700">
                                    Anche per me
                                </button>
                            </li>
                        </template>
                    </ul>
                    <button type="button" @click="ignora = true" class="mt-3 text-xs text-yellow-700 underline">
                        È un problema diverso, continuo
                    </button>
                </div>
            </template>

            {{-- 4. Priorità e tempo previsto (indicativo: la priorità la decide l'ufficio) --}}
            <div x-data="{ priorita: {{ (int) old('livello_priorita', 2) }}, tempi: @js($tempiPrevisti) }">
                <x-input-label value="Priorità" />
                <input type="hidden" name="livello_priorita" :value="priorita">
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach([1 => 'Bassa', 2 => 'Media', 3 => 'Alta', 4 => 'Critica'] as $val => $label)
                        <button type="button"
                                @click="priorita = {{ $val }}"
                                :class="priorita == {{ $val }} ? 'pa-priorita-{{ $val }} {{ \App\Support\Colori::priorita($val) ? 'pa-tono-'.\App\Support\Colori::priorita($val) : '' }} pa-riquadro-scelto' : ''"
                                :aria-pressed="priorita == {{ $val }}"
                                class="pa-scelta-priorita">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
                <template x-if="tempi[tipologiaId]?.[priorita] ?? tempi[0]?.[priorita]">
                    <p class="mt-3 text-sm text-gray-700">
                        Intervento previsto entro <strong x-text="tempi[tipologiaId]?.[priorita] ?? tempi[0]?.[priorita]"></strong>.
                    </p>
                </template>
                <p class="mt-1 text-xs text-gray-500">La priorità definitiva la stabilisce l'ufficio.</p>
                <x-input-error :messages="$errors->get('livello_priorita')" class="mt-1" />
            </div>

            {{-- 5. Titolo --}}
            <div>
                <x-input-label for="titolo" value="Titolo *" />
                <x-text-input id="titolo" name="titolo" type="text" maxlength="100" required
                              class="mt-1 block w-full @error('titolo') border-red-500 @enderror"
                              :value="old('titolo')" placeholder="Il problema in poche parole" />
                <p class="mt-1 text-xs text-gray-500">Massimo 100 caratteri.</p>
                <x-input-error :messages="$errors->get('titolo')" class="mt-1" />
            </div>

            {{-- 6. Descrizione --}}
            <div>
                <x-input-label for="testo_segnalazione" value="Descrizione del problema *" />
                <textarea id="testo_segnalazione" name="testo_segnalazione" rows="5" maxlength="2000" required
                          class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 @error('testo_segnalazione') border-red-500 @enderror"
                          placeholder="Cosa succede, dove esattamente (piano, aula, locale), da quando">{{ old('testo_segnalazione') }}</textarea>
                <x-input-error :messages="$errors->get('testo_segnalazione')" class="mt-1" />
            </div>

            {{-- 7. Dove (facoltativo) --}}
            <div>
                <x-input-label value="Dove si trova il problema" />
                <div class="mt-2 flex flex-wrap gap-x-4 gap-y-2">
                    @foreach([0 => 'Non so', 1 => 'Interno edificio', 2 => 'Esterno', 3 => 'Impianto', 4 => 'Area verde'] as $val => $label)
                        <label class="inline-flex items-center gap-1.5 cursor-pointer">
                            <input type="radio" name="ubicazione_tipo" value="{{ $val }}"
                                   @checked((string) old('ubicazione_tipo', '0') === (string) $val)
                                   class="text-blue-600 focus:ring-blue-500">
                            <span class="text-sm text-gray-700">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('ubicazione_tipo')" class="mt-1" />
            </div>

            {{-- 8. Foto (facoltative) --}}
            <div>
                <x-input-label for="allegati_create" value="Foto (facoltative)" />
                <p class="text-xs text-gray-500 mb-2">
                    Fino a {{ $allegatiMaxPerRequest }} file, {{ $allegatiMaxSizeMb }}&nbsp;MB ciascuno.
                </p>
                <x-pa.foto id="allegati_create" :mime="$allegatiMime" ::disabled="esistente && !diversa" />
                <x-input-error :messages="$errors->get('allegati')" class="mt-1" />
                <x-input-error :messages="$errors->get('allegati.*')" class="mt-1" />
            </div>

            {{-- 9. Avviso 112 e invio --}}
            <p class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                Per pericoli immediati (incendio, fuga di gas, crolli) chiama il 112: il comune interviene in orario d'ufficio.
            </p>

            <div class="flex items-center justify-end gap-3 pt-2 border-t border-gray-100">
                <a href="{{ url()->previous() }}" class="text-sm text-gray-500 hover:text-gray-700">Annulla</a>
                <button type="submit" dusk="submit-segnalazione"
                        class="inline-flex items-center px-5 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-sm text-white uppercase tracking-widest hover:bg-blue-700 transition">
                    Invia segnalazione
                </button>
            </div>
            </div>
        </form>
    </div>

    @push('scripts')
        <script>
        async function controllaSimili(data) {
            if (!data.tipologiaId || !data.sede) { data.simili = []; data.esistente = null; return; }
            const params = new URLSearchParams({ id_tipologia_segnalazione: data.tipologiaId, id_plesso: data.sede });
            try {
                const es = await fetch('{{ route('segnalazioni.esistente') }}?' + params, { headers: { 'Accept': 'application/json' } });
                data.esistente = es.ok ? (await es.json()).esistente : null;
            } catch (e) {
                data.esistente = null;
            }
            try {
                const res = await fetch('{{ route('segnalazioni.simili') }}?' + params, { headers: { 'Accept': 'application/json' } });
                data.simili = res.ok ? await res.json() : [];
            } catch (e) {
                data.simili = [];
            }
        }

        let _aderisciSubmitting = false;
        function aderisciSimile(id) {
            if (_aderisciSubmitting) return;
            _aderisciSubmitting = true;
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '{{ url('segnalazioni') }}/' + id + '/adesioni';
            const csrf = document.createElement('input');
            csrf.type = 'hidden'; csrf.name = '_token'; csrf.value = '{{ csrf_token() }}';
            form.appendChild(csrf);
            document.body.appendChild(form);
            form.submit();
        }
        </script>
    @endpush
</x-app-layout>
