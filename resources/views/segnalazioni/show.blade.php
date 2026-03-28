<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Segnalazione #{{ $segnalazione->id_segnalazione }}
                </h2>
                @if($segnalazione->stato)
                    <span class="{{ $segnalazione->stato->badgeClass() }} inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium">
                        {{ $segnalazione->stato->descrizione }}
                    </span>
                @endif
                @if($segnalazione->flag_evidenza)
                    <span class="text-yellow-500 text-lg" title="In evidenza">&#9733;</span>
                @endif
            </div>
            @can('update', $segnalazione)
                <form method="POST" action="{{ route('segnalazioni.evidenza', $segnalazione->id_segnalazione) }}">
                    @csrf
                    <button type="submit"
                            class="text-xs {{ $segnalazione->flag_evidenza ? 'text-yellow-600 hover:text-gray-500' : 'text-gray-400 hover:text-yellow-500' }} transition">
                        {{ $segnalazione->flag_evidenza ? '★ Rimuovi da evidenza' : '☆ Metti in evidenza' }}
                    </button>
                </form>
            @endcan
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8"
             x-data="{ tab: window.location.hash === '#note' ? 'note' : (window.location.hash === '#storico' ? 'storico' : (window.location.hash === '#gestione' ? 'gestione' : 'dati')) }">

            {{-- Tab navigation --}}
            <div class="border-b border-gray-200 mb-4">
                <nav class="-mb-px flex space-x-6">
                    <button @click="tab = 'dati'"
                            :class="tab === 'dati' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="whitespace-nowrap pb-3 px-1 border-b-2 font-medium text-sm">
                        Dati
                    </button>
                    <button @click="tab = 'note'"
                            :class="tab === 'note' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="whitespace-nowrap pb-3 px-1 border-b-2 font-medium text-sm">
                        Note
                        @if($segnalazione->note->count() > 0)
                            <span class="ml-1 bg-gray-100 text-gray-600 text-xs px-1.5 py-0.5 rounded-full">{{ $segnalazione->note->count() }}</span>
                        @endif
                    </button>
                    <button @click="tab = 'storico'"
                            :class="tab === 'storico' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="whitespace-nowrap pb-3 px-1 border-b-2 font-medium text-sm">
                        Storico
                    </button>
                    @can('update', $segnalazione)
                        <button @click="tab = 'gestione'"
                                :class="tab === 'gestione' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                                class="whitespace-nowrap pb-3 px-1 border-b-2 font-medium text-sm">
                            Gestione
                            @if($azioniDisponibili->count() > 0)
                                <span class="ml-1 bg-blue-100 text-blue-600 text-xs px-1.5 py-0.5 rounded-full">{{ $azioniDisponibili->count() }}</span>
                            @endif
                        </button>
                    @endcan
                </nav>
            </div>

            {{-- TAB: Dati --}}
            <div x-show="tab === 'dati'" class="space-y-4">
                <div class="bg-white shadow-sm rounded-lg p-6">
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-4 text-sm">
                        <div>
                            <dt class="font-medium text-gray-500">Tipologia</dt>
                            <dd class="mt-1 text-gray-900">
                                @if($segnalazione->tipologia)
                                    <span class="text-gray-400 text-xs">{{ $segnalazione->tipologia->gruppo?->descrizione }}</span><br>
                                    {{ $segnalazione->tipologia->descrizione }}
                                @else
                                    —
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium text-gray-500">Provenienza</dt>
                            <dd class="mt-1 text-gray-900">{{ $segnalazione->provenienza?->descrizione ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-gray-500">Data segnalazione</dt>
                            <dd class="mt-1 text-gray-900">{{ $segnalazione->data_segnalazione?->format('d/m/Y H:i') ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-gray-500">Data chiusura</dt>
                            <dd class="mt-1 text-gray-900">{{ $segnalazione->data_chiusura ? \Carbon\Carbon::parse($segnalazione->data_chiusura)->format('d/m/Y H:i') : '—' }}</dd>
                        </div>
                        @if($segnalazione->plesso)
                            <div>
                                <dt class="font-medium text-gray-500">Plesso / Sede</dt>
                                <dd class="mt-1 text-gray-900">
                                    {{ $segnalazione->plesso->nome }}
                                    @if($segnalazione->plesso->istituto)
                                        <span class="text-gray-400 text-xs block">{{ $segnalazione->plesso->istituto->nome }}</span>
                                    @endif
                                </dd>
                            </div>
                        @endif
                        <div>
                            <dt class="font-medium text-gray-500">Operatore assegnato</dt>
                            <dd class="mt-1 text-gray-900">{{ $segnalazione->operatore?->name ?? '—' }}</dd>
                        </div>
                        @if($segnalazione->segnalante)
                            <div>
                                <dt class="font-medium text-gray-500">Segnalante</dt>
                                <dd class="mt-1 text-gray-900">
                                    {{ $segnalazione->segnalante }}
                                    @if($segnalazione->email)
                                        <a href="mailto:{{ $segnalazione->email }}" class="text-blue-600 text-xs ml-1">{{ $segnalazione->email }}</a>
                                    @endif
                                    @if($segnalazione->telefono)
                                        <span class="text-gray-400 text-xs ml-1">{{ $segnalazione->telefono }}</span>
                                    @endif
                                </dd>
                            </div>
                        @endif
                        <div class="sm:col-span-2">
                            <dt class="font-medium text-gray-500">Descrizione</dt>
                            <dd class="mt-1 text-gray-900 whitespace-pre-wrap">{{ $segnalazione->testo_segnalazione }}</dd>
                        </div>
                    </dl>
                </div>
            </div>

            {{-- TAB: Note --}}
            <div x-show="tab === 'note'" id="note" class="space-y-4">
                {{-- Elenco note --}}
                @forelse($segnalazione->note as $nota)
                    <div class="bg-white shadow-sm rounded-lg p-4 text-sm">
                        <div class="flex items-start justify-between mb-2">
                            <div class="font-medium text-gray-700">{{ $nota->autore?->name ?? 'Sistema' }}</div>
                            <div class="text-gray-400 text-xs">
                                {{ $nota->data?->format('d/m/Y H:i') }}
                                @if($nota->visibile_web)
                                    <span class="ml-2 bg-green-100 text-green-700 px-1.5 py-0.5 rounded text-xs">web</span>
                                @endif
                                @if($nota->visibile_impresa)
                                    <span class="ml-1 bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded text-xs">impresa</span>
                                @endif
                            </div>
                        </div>
                        <div class="text-gray-800 whitespace-pre-wrap">{{ $nota->testo }}</div>
                    </div>
                @empty
                    <div class="bg-white shadow-sm rounded-lg p-6 text-center text-gray-400 text-sm">
                        Nessuna nota.
                    </div>
                @endforelse

                {{-- Form aggiungi nota --}}
                <div class="bg-white shadow-sm rounded-lg p-5">
                    <h3 class="font-medium text-gray-700 text-sm mb-3">Aggiungi nota</h3>
                    <form method="POST" action="{{ route('segnalazioni.nota', $segnalazione->id_segnalazione) }}" class="space-y-3">
                        @csrf
                        <div>
                            <textarea name="testo"
                                      rows="3"
                                      maxlength="2000"
                                      class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm"
                                      placeholder="Testo della nota..."
                                      required></textarea>
                            <x-input-error :messages="$errors->get('testo')" class="mt-1" />
                        </div>
                        @can('update', $segnalazione)
                            <div class="flex items-center gap-4 text-sm">
                                <label class="flex items-center gap-1.5 text-gray-600">
                                    <input type="checkbox" name="visibile_web" value="1" class="rounded border-gray-300">
                                    Visibile web
                                </label>
                                <label class="flex items-center gap-1.5 text-gray-600">
                                    <input type="checkbox" name="visibile_impresa" value="1" class="rounded border-gray-300">
                                    Visibile impresa
                                </label>
                            </div>
                        @endcan
                        <div class="text-right">
                            <button type="submit"
                                    class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 transition">
                                Aggiungi nota
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            {{-- TAB: Storico --}}
            <div x-show="tab === 'storico'" id="storico">
                <div class="bg-white shadow-sm rounded-lg overflow-hidden">
                    @if($segnalazione->storicoStati->isEmpty())
                        <div class="p-6 text-center text-gray-400 text-sm">Nessuna transizione registrata.</div>
                    @else
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Data</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stato</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Utente</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-100">
                                @foreach($segnalazione->storicoStati->sortByDesc('data_registrazione') as $h)
                                    <tr>
                                        <td class="px-4 py-3 text-gray-500 whitespace-nowrap">
                                            {{ $h->data_registrazione ? \Carbon\Carbon::parse($h->data_registrazione)->format('d/m/Y H:i') : '—' }}
                                        </td>
                                        <td class="px-4 py-3">
                                            @if($h->stato)
                                                <span class="{{ $h->stato->badgeClass() }} inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium">
                                                    {{ $h->stato->descrizione }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-gray-500">{{ $h->utente?->name ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>

            {{-- TAB: Gestione --}}
            @can('update', $segnalazione)
                <div x-show="tab === 'gestione'" id="gestione" class="space-y-4">
                    @if($segnalazione->isChiusa())
                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-center text-gray-500 text-sm">
                            Segnalazione chiusa — nessuna azione disponibile.
                        </div>
                    @elseif($azioniDisponibili->isEmpty())
                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-center text-gray-500 text-sm">
                            Nessuna azione disponibile.
                        </div>
                    @else
                        <div class="bg-white shadow-sm rounded-lg p-5">
                            <h3 class="font-medium text-gray-700 text-sm mb-4">Esegui azione</h3>
                            <form method="POST" action="{{ route('segnalazioni.azione', $segnalazione->id_segnalazione) }}"
                                  x-data="{ azioneId: null, flagOperatore: false, flagAppalto: false,
                                    azioni: {{ $azioniDisponibili->keyBy('id_azione')->map(fn($a) => ['flag_operatore' => (bool)$a->flag_operatore, 'flag_appalto' => (bool)$a->flag_appalto])->toJson() }} }"
                                  @change="
                                    let a = azioni[azioneId];
                                    flagOperatore = a ? a.flag_operatore : false;
                                    flagAppalto = a ? a.flag_appalto : false;
                                  "
                                  class="space-y-4">
                                @csrf

                                {{-- Selezione azione --}}
                                <div>
                                    <x-input-label for="id_azione" value="Azione *" />
                                    <select id="id_azione" name="id_azione"
                                            x-model="azioneId"
                                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm"
                                            required>
                                        <option value="">— Seleziona azione —</option>
                                        @foreach($azioniDisponibili as $azione)
                                            <option value="{{ $azione->id_azione }}">
                                                {{ $azione->descrizione }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <x-input-error :messages="$errors->get('id_azione')" class="mt-1" />
                                </div>

                                {{-- Operatore (se flag_operatore) --}}
                                <div x-show="flagOperatore" x-cloak>
                                    <x-input-label for="id_operatore" value="Assegna a operatore" />
                                    <select id="id_operatore" name="id_operatore"
                                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                        <option value="">— Nessuno —</option>
                                        @foreach($operatori as $op)
                                            <option value="{{ $op->id }}">{{ $op->name }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                {{-- Appalto (se flag_appalto) --}}
                                <div x-show="flagAppalto" x-cloak>
                                    <x-input-label for="id_appalto" value="ID Appalto" />
                                    <x-text-input id="id_appalto" name="id_appalto" type="number"
                                        class="mt-1 block w-full text-sm"
                                        placeholder="Numero appalto" />
                                </div>

                                {{-- Nota opzionale --}}
                                <div>
                                    <x-input-label for="nota" value="Nota (opzionale)" />
                                    <textarea id="nota" name="nota"
                                              rows="2"
                                              maxlength="2000"
                                              class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm"
                                              placeholder="Nota interna..."></textarea>
                                </div>

                                <div class="text-right">
                                    <button type="submit"
                                            class="inline-flex items-center px-5 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">
                                        Esegui azione
                                    </button>
                                </div>
                            </form>
                        </div>
                    @endif
                </div>
            @endcan

        </div>
    </div>
</x-app-layout>
