<x-app-layout>
    <x-slot name="header">{{ $istituto['nome'] }}</x-slot>
    <x-slot name="actions">
        <a href="{{ route('admin.anagrafe-miur.index') }}"
           class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 transition">
            Indietro
        </a>
    </x-slot>

    <div class="bg-white shadow-sm rounded-xl p-6 space-y-4">
        <dl class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
            <div><dt class="text-gray-500">Codice</dt><dd>{{ $istituto['codice'] }}</dd></div>
            <div><dt class="text-gray-500">Email segreteria</dt><dd>{{ $istituto['email'] ?: '—' }}</dd></div>
            <div><dt class="text-gray-500">In ProntoPA</dt><dd>{{ $locale ? ($locale->isMiur() ? 'Sì, allineato al MIUR' : 'Sì, anagrafica manuale (verrà allineata al salvataggio)') : 'No' }}</dd></div>
        </dl>

        <form method="POST" action="{{ route('admin.anagrafe-miur.salva', $istituto['codice']) }}">
            @csrf
            <p class="text-sm text-gray-600 mb-3">Seleziona le sedi da gestire in ProntoPA. Nome, indirizzo ed email arrivano dal MIUR. Togliere la spunta non elimina una sede già presente.</p>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100 text-sm">
                    <thead class="bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wider">
                        <tr>
                            <th class="px-3 py-2"></th>
                            <th class="px-3 py-2 text-left">Codice</th>
                            <th class="px-3 py-2 text-left">Nome</th>
                            <th class="px-3 py-2 text-left">Grado</th>
                            <th class="px-3 py-2 text-left">Comune</th>
                            <th class="px-3 py-2 text-left">Indirizzo</th>
                            <th class="px-3 py-2 text-left">Stato</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach($istituto['sedi'] as $sede)
                            @php($presente = isset($presenti[$sede['codice']]))
                            <tr>
                                <td class="px-3 py-2">
                                    <input type="checkbox" name="sedi[]" id="sede-{{ $sede['codice'] }}" value="{{ $sede['codice'] }}"{{ $presente ? ' checked' : '' }}
                                           class="rounded border-gray-300 text-blue-600">
                                </td>
                                <td class="px-3 py-2"><label for="sede-{{ $sede['codice'] }}">{{ $sede['codice'] }}</label></td>
                                <td class="px-3 py-2">
                                    {{ $sede['nome'] }}
                                    @if($sede['sede_amministrativa'])
                                        <span class="ml-1 text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">Sede amministrativa</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">{{ $sede['grado'] }}</td>
                                <td class="px-3 py-2">
                                    {{ $sede['comune'] }}
                                    @if($comune !== '' && $sede['comune'] !== $comune)
                                        <span class="ml-1 text-xs px-2 py-0.5 rounded-full bg-amber-50 text-amber-700">Fuori comune</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">{{ $sede['indirizzo'] }}</td>
                                <td class="px-3 py-2">{{ $presente ? 'Presente' : 'Nuova' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <button type="submit" class="mt-4 inline-flex items-center px-3 py-1.5 bg-blue-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">
                Salva selezione
            </button>
        </form>
    </div>
</x-app-layout>
