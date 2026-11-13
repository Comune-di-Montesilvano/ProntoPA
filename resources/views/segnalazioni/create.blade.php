<x-app-layout>
    <x-slot name="header">Nuova segnalazione</x-slot>
    <x-slot name="actions">
        <a href="{{ url()->previous() }}"
           class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 transition">
            Annulla
        </a>
    </x-slot>

    @php
        $sedeScelta = old('id_plesso', $plessi->count() === 1 ? $plessi->first()->id_plesso : null);
        $allegatiMaxSizeMb     = (int) \App\Models\Impostazione::get('allegati_max_size_mb', 10);
        $allegatiMaxPerRequest = (int) \App\Models\Impostazione::get('allegati_max_per_request', 5);
        $allegatiMime          = \App\Models\Impostazione::get('allegati_mime_consentiti', 'image/jpeg,image/png');
    @endphp

    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
        <form method="POST" action="{{ route('segnalazioni.store') }}" class="p-6 space-y-6"
              enctype="multipart/form-data"
              x-data="{
                  sede: @js($sedeScelta ? (string) $sedeScelta : ''),
                  tipologiaId: @js(old('id_tipologia_segnalazione') ? (int) old('id_tipologia_segnalazione') : null),
                  simili: [],
                  ignora: false,
                  indirizzo() { return this.$refs.sede?.selectedOptions[0]?.dataset.indirizzo ?? '' },
              }"
              x-init="$watch('tipologiaId', () => { ignora = false; controllaSimili($data) });
                      $watch('sede', () => { ignora = false; controllaSimili($data) });
                      controllaSimili($data)">
            @csrf

            {{-- 1. Sede --}}
            <div>
                <x-input-label for="id_plesso" value="Sede *" />
                @if($plessi->isEmpty())
                    <p class="mt-1 rounded-md border border-yellow-300 bg-yellow-50 px-3 py-2 text-sm text-yellow-800">
                        Non hai sedi su cui segnalare. Le scuole segnalano per le sedi coperte dalle loro deleghe.
                    </p>
                @endif
                <select id="id_plesso" name="id_plesso" required x-ref="sede" x-model="sede"
                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 @error('id_plesso') border-red-500 @enderror">
                    @if($plessi->count() !== 1)
                        <option value="">— Scegli la sede —</option>
                    @endif
                    @foreach($plessi->groupBy(fn ($p) => $p->istituto?->categoria?->nome ?? 'Altro') as $categoria => $items)
                        <optgroup label="{{ $categoria }}">
                            @foreach($items as $p)
                                <option value="{{ $p->id_plesso }}" @selected((string) $sedeScelta === (string) $p->id_plesso) data-indirizzo="{{ $p->indirizzo }}">
                                    {{ $p->istituto ? $p->istituto->descrizione.' — ' : '' }}{{ $p->etichetta() }}
                                </option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                <p class="mt-1 text-sm text-gray-600" x-show="indirizzo()" x-text="indirizzo()"></p>
                <x-input-error :messages="$errors->get('id_plesso')" class="mt-1" />
            </div>

            {{-- 2. Tipologia --}}
            <div>
                <x-input-label value="Tipologia *" />
                <input type="hidden" name="id_tipologia_segnalazione" :value="tipologiaId">
                <div class="mt-2 grid grid-cols-2 sm:grid-cols-4 md:grid-cols-6 gap-2">
                    @foreach($tipologie as $t)
                        <button type="button"
                                @click="tipologiaId = {{ $t->id_tipologia_segnalazione }}"
                                :class="tipologiaId == {{ $t->id_tipologia_segnalazione }}
                                    ? 'border-blue-500 bg-blue-50 text-blue-700'
                                    : 'border-gray-200 bg-white text-gray-500 hover:border-blue-300 hover:text-blue-600'"
                                class="flex flex-col items-center gap-1.5 px-2 py-3 border-2 rounded-lg transition cursor-pointer select-none">
                            <span><i class="{{ $t->icona ?? 'fas fa-circle' }} text-lg"></i></span>
                            <span class="text-xs font-semibold uppercase text-center leading-tight">{{ $t->descrizione }}</span>
                        </button>
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('id_tipologia_segnalazione')" class="mt-1" />
            </div>

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
                                :class="priorita == {{ $val }}
                                    ? '{{ match($val) { 1 => 'bg-gray-200 text-gray-700 border-gray-400', 2 => 'bg-blue-100 text-blue-700 border-blue-400', 3 => 'bg-orange-100 text-orange-700 border-orange-400', 4 => 'bg-red-100 text-red-700 border-red-500' } }}'
                                    : 'bg-white text-gray-500 border-gray-200 hover:border-gray-400'"
                                class="px-4 py-2 border-2 rounded-lg text-sm font-semibold transition select-none">
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
                <input id="allegati_create" type="file" name="allegati[]" multiple
                       accept="{{ $allegatiMime }}"
                       class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100" />
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
        </form>
    </div>

    @push('scripts')
        <script>
        async function controllaSimili(data) {
            if (!data.tipologiaId || !data.sede) { data.simili = []; return; }
            const params = new URLSearchParams({ id_tipologia_segnalazione: data.tipologiaId, id_plesso: data.sede });
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
