<x-app-layout>
    <x-slot name="header">{{ $squadra->id_squadra ? 'Modifica squadra' : 'Nuova squadra' }}</x-slot>
    <x-slot name="actions">
        <a href="{{ route('admin.squadre.index') }}"
           class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 transition">
            Annulla
        </a>
    </x-slot>

    <div class="max-w-xl">
        <div class="bg-white shadow-sm rounded-xl p-6">
            <form method="POST"
                  action="{{ $squadra->id_squadra ? route('admin.squadre.update', $squadra->id_squadra) : route('admin.squadre.store') }}">
                @csrf
                @if($squadra->id_squadra) @method('PUT') @endif

                <div class="space-y-5">
                    <div>
                        <x-input-label for="nome" value="Nome squadra *" />
                        <input type="text" id="nome" name="nome" maxlength="100" required
                               value="{{ old('nome', $squadra->nome) }}"
                               class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500 @error('nome') border-red-500 @enderror">
                        <x-input-error :messages="$errors->get('nome')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="id_caposquadra" value="Caposquadra *" />
                        <select id="id_caposquadra" name="id_caposquadra" required
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500 @error('id_caposquadra') border-red-500 @enderror">
                            <option value="">— Seleziona —</option>
                            @foreach($operai as $op)
                                <option value="{{ $op->id }}"
                                    {{ old('id_caposquadra', $squadra->id_caposquadra) == $op->id ? 'selected' : '' }}>
                                    {{ $op->name }}
                                </option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('id_caposquadra')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="membri" value="Membri (Ctrl+clic per selezione multipla)" />
                        @php $membroIds = $squadra->membri?->pluck('id')->toArray() ?? []; @endphp
                        <select id="membri" name="membri[]" multiple
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500"
                                style="min-height: 120px;">
                            @foreach($operai as $op)
                                <option value="{{ $op->id }}"
                                    {{ in_array($op->id, old('membri', $membroIds)) ? 'selected' : '' }}>
                                    {{ $op->name }}
                                </option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('membri')" class="mt-1" />
                    </div>

                    @if($squadra->id_squadra)
                        <div class="flex items-center gap-2">
                            <input type="checkbox" id="attiva" name="attiva" value="1"
                                   {{ old('attiva', $squadra->attiva) ? 'checked' : '' }}
                                   class="rounded border-gray-300">
                            <label for="attiva" class="text-sm text-gray-700">Squadra attiva</label>
                        </div>
                    @endif

                    <div class="flex justify-end pt-2 border-t border-gray-100">
                        <button type="submit"
                                class="inline-flex items-center px-5 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-sm text-white uppercase tracking-widest hover:bg-blue-700 transition">
                            {{ $squadra->id_squadra ? 'Salva modifiche' : 'Crea squadra' }}
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
