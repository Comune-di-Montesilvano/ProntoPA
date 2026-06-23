<x-app-layout>
    <x-slot name="header">Gestione Squadre</x-slot>
    <x-slot name="actions">
        <a href="{{ route('admin.squadre.create') }}"
           class="inline-flex items-center px-3 py-1.5 bg-blue-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">
            + Nuova squadra
        </a>
    </x-slot>

    @if(session('success'))
        <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 rounded-lg text-green-800 text-sm">
            {{ session('success') }}
        </div>
    @endif

    <div class="bg-white shadow-sm rounded-xl overflow-hidden">
        @if($squadre->isEmpty())
            <div class="p-10 text-center text-gray-400 text-sm">
                Nessuna squadra configurata.
            </div>
        @else
            <table class="min-w-full divide-y divide-gray-100 text-sm">
                <thead class="bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wider">
                    <tr>
                        <th class="px-4 py-3 text-left">Nome</th>
                        <th class="px-4 py-3 text-left">Caposquadra</th>
                        <th class="px-4 py-3 text-left">Membri</th>
                        <th class="px-4 py-3 text-left">Attiva</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @foreach($squadre as $s)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-gray-800">{{ $s->nome }}</td>
                            <td class="px-4 py-3 text-gray-700">{{ $s->caposquadra?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ $s->membri->count() }}</td>
                            <td class="px-4 py-3">
                                @if($s->attiva)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700">Sì</span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-500">No</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right flex gap-2 justify-end">
                                <a href="{{ route('admin.squadre.edit', $s->id_squadra) }}"
                                   class="text-blue-600 hover:text-blue-800 text-xs font-medium">Modifica</a>
                                <form method="POST" action="{{ route('admin.squadre.destroy', $s->id_squadra) }}"
                                      onsubmit="return confirm('Disattivare questa squadra?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="text-red-500 hover:text-red-700 text-xs font-medium">Disattiva</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</x-app-layout>
