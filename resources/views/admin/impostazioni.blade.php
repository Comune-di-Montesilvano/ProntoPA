<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Impostazioni ente</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <form method="POST" action="{{ route('admin.impostazioni.update') }}">
                @csrf @method('PATCH')

                @foreach($impostazioni as $gruppo => $voci)
                    <div class="bg-white shadow-sm rounded-lg mb-6 overflow-hidden">
                        <div class="px-5 py-3 bg-gray-50 border-b border-gray-200">
                            <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                {{ match($gruppo) {
                                    'brand'   => 'Brandizzazione ente',
                                    'email'   => 'Email / Notifiche',
                                    'mappa'   => 'Mappa (OpenStreetMap)',
                                    'webhook' => 'Webhook cittadini',
                                    default   => ucfirst($gruppo),
                                } }}
                            </h3>
                        </div>
                        <div class="p-5 space-y-4">
                            @foreach($voci as $imp)
                                <div>
                                    <label for="imp_{{ $imp->chiave }}"
                                           class="block text-sm font-medium text-gray-700 mb-1">
                                        {{ $imp->descrizione ?? $imp->chiave }}
                                    </label>

                                    @if($imp->tipo === 'boolean')
                                        <select id="imp_{{ $imp->chiave }}"
                                                name="impostazioni[{{ $imp->chiave }}]"
                                                class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                            <option value="1" {{ $imp->valore == '1' ? 'selected' : '' }}>Sì</option>
                                            <option value="0" {{ $imp->valore != '1' ? 'selected' : '' }}>No</option>
                                        </select>
                                    @elseif($imp->tipo === 'color')
                                        <div class="flex items-center gap-3">
                                            <input type="color"
                                                   id="imp_{{ $imp->chiave }}"
                                                   name="impostazioni[{{ $imp->chiave }}]"
                                                   value="{{ $imp->valore ?? '#1D4ED8' }}"
                                                   class="h-9 w-16 rounded border border-gray-300 cursor-pointer p-0.5">
                                            <span class="text-xs text-gray-400 font-mono">{{ $imp->valore }}</span>
                                        </div>
                                    @elseif($imp->tipo === 'integer')
                                        <input type="number"
                                               id="imp_{{ $imp->chiave }}"
                                               name="impostazioni[{{ $imp->chiave }}]"
                                               value="{{ old('impostazioni.'.$imp->chiave, $imp->valore) }}"
                                               class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                    @else
                                        <input type="{{ $imp->tipo === 'url' ? 'url' : 'text' }}"
                                               id="imp_{{ $imp->chiave }}"
                                               name="impostazioni[{{ $imp->chiave }}]"
                                               value="{{ old('impostazioni.'.$imp->chiave, $imp->valore) }}"
                                               class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm"
                                               placeholder="{{ $imp->chiave }}">
                                    @endif

                                    <p class="mt-0.5 text-xs text-gray-400 font-mono">{{ $imp->chiave }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                <div class="flex justify-end">
                    <button type="submit"
                            class="inline-flex items-center px-6 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">
                        Salva impostazioni
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
