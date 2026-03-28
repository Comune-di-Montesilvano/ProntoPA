<x-app-layout>
    <x-slot name="header">Nuovo utente</x-slot>
    <x-slot name="actions">
        <a href="{{ route('admin.utenti.index') }}"
           class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 transition">
            Annulla
        </a>
    </x-slot>

    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
        <form method="POST" action="{{ route('admin.utenti.store') }}" class="p-6 space-y-5 max-w-2xl">
            @csrf

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="name" value="Nome completo *" />
                    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                        :value="old('name')" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="username" value="Username *" />
                    <x-text-input id="username" name="username" type="text" class="mt-1 block w-full"
                        :value="old('username')" required autocomplete="off" />
                    <x-input-error :messages="$errors->get('username')" class="mt-1" />
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="email" value="Email *" />
                    <x-text-input id="email" name="email" type="email" class="mt-1 block w-full"
                        :value="old('email')" required />
                    <x-input-error :messages="$errors->get('email')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="telefono" value="Telefono" />
                    <x-text-input id="telefono" name="telefono" type="text" class="mt-1 block w-full"
                        :value="old('telefono')" />
                    <x-input-error :messages="$errors->get('telefono')" class="mt-1" />
                </div>
            </div>

            <div>
                <x-input-label for="password" value="Password *" />
                <x-text-input id="password" name="password" type="password" class="mt-1 block w-full"
                    required autocomplete="new-password" />
                <x-input-error :messages="$errors->get('password')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="ruolo" value="Ruolo *" />
                <select id="ruolo" name="ruolo"
                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm"
                        required x-data x-on:change="$dispatch('ruolo-changed', {ruolo: $event.target.value})">
                    <option value="">— Seleziona —</option>
                    <option value="admin"       {{ old('ruolo') === 'admin'       ? 'selected' : '' }}>Admin</option>
                    <option value="gestore"     {{ old('ruolo') === 'gestore'     ? 'selected' : '' }}>Gestore</option>
                    <option value="segnalatore" {{ old('ruolo') === 'segnalatore' ? 'selected' : '' }}>Segnalatore</option>
                    <option value="impresa"     {{ old('ruolo') === 'impresa'     ? 'selected' : '' }}>Impresa</option>
                </select>
                <x-input-error :messages="$errors->get('ruolo')" class="mt-1" />
            </div>

            {{-- Supervisore — solo per gestore --}}
            <div x-data="{ show: '{{ old('ruolo') }}' === 'gestore' }"
                 x-on:ruolo-changed.window="show = $event.detail.ruolo === 'gestore'"
                 x-show="show">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="supervisore" value="1"
                           class="rounded border-gray-300 text-blue-600 shadow-sm"
                           {{ old('supervisore') ? 'checked' : '' }}>
                    <span class="text-sm text-gray-700">Supervisore (vede tutte le segnalazioni)</span>
                </label>
            </div>

            {{-- Profilo — per segnalatori scuola --}}
            <div>
                <x-input-label for="id_profilo" value="Profilo" />
                <select id="id_profilo" name="id_profilo"
                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                    <option value="">— Nessuno —</option>
                    @foreach($profili as $p)
                        <option value="{{ $p->id_profilo }}"
                            {{ old('id_profilo') == $p->id_profilo ? 'selected' : '' }}>
                            {{ $p->descrizione }}
                            @if($p->limita_istituto && $p->istituto) ({{ $p->istituto->descrizione }}) @endif
                        </option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-400">Per gli utenti delle scuole: seleziona il profilo dell'istituto di appartenenza.</p>
                <x-input-error :messages="$errors->get('id_profilo')" class="mt-1" />
            </div>

            {{-- Provenienza --}}
            <div>
                <x-input-label for="id_provenienza" value="Provenienza" />
                <select id="id_provenienza" name="id_provenienza"
                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                    <option value="">— Nessuna —</option>
                    @foreach($provenienze as $p)
                        <option value="{{ $p->id_provenienza }}"
                            {{ old('id_provenienza') == $p->id_provenienza ? 'selected' : '' }}>
                            {{ $p->descrizione }}
                        </option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('id_provenienza')" class="mt-1" />
            </div>

            {{-- Impresa — solo per ruolo impresa --}}
            <div x-data="{ show: '{{ old('ruolo') }}' === 'impresa' }"
                 x-on:ruolo-changed.window="show = $event.detail.ruolo === 'impresa'"
                 x-show="show">
                <x-input-label for="id_impresa" value="Impresa" />
                <select id="id_impresa" name="id_impresa"
                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                    <option value="">— Nessuna —</option>
                    @foreach($imprese as $i)
                        <option value="{{ $i->id_impresa }}"
                            {{ old('id_impresa') == $i->id_impresa ? 'selected' : '' }}>
                            {{ $i->ragione_sociale }}
                        </option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('id_impresa')" class="mt-1" />
            </div>

            <div class="flex items-center justify-end gap-3 pt-2 border-t border-gray-100">
                <a href="{{ route('admin.utenti.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Annulla</a>
                <button type="submit"
                        class="inline-flex items-center px-5 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">
                    Crea utente
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
