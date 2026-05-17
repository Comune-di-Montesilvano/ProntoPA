<x-app-layout>
    <x-slot name="header">Configurazione SLA</x-slot>
    <x-slot name="actions">
        <a href="{{ route('admin.sla.create') }}"
           class="inline-flex items-center px-3 py-1.5 bg-blue-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">
            + Nuova regola
        </a>
    </x-slot>

    @if(session('success'))
        <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 rounded-lg text-green-800 text-sm">
            {{ session('success') }}
        </div>
    @endif

    <div class="bg-white shadow-sm rounded-xl overflow-hidden">
        @if($regole->isEmpty())
            <div class="p-10 text-center text-gray-400 text-sm">
                Nessuna regola SLA configurata. Le segnalazioni non avranno scadenza automatica.
            </div>
        @else
            <table class="min-w-full divide-y divide-gray-100 text-sm">
                <thead class="bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wider">
                    <tr>
                        <th class="px-4 py-3 text-left">Tipologia</th>
                        <th class="px-4 py-3 text-left">Specializzazione</th>
                        <th class="px-4 py-3 text-left">Priorità</th>
                        <th class="px-4 py-3 text-left">Ore target</th>
                        <th class="px-4 py-3 text-left">Ore warning</th>
                        <th class="px-4 py-3 text-left">Descrizione</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @foreach($regole as $r)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-gray-700">{{ $r->tipologia?->descrizione ?? '— Tutte —' }}</td>
                            <td class="px-4 py-3 text-gray-700">{{ $r->specializzazione?->descrizione ?? '— Tutte —' }}</td>
                            <td class="px-4 py-3">
                                @php $labels = [1=>'Bassa',2=>'Media',3=>'Alta',4=>'Critica']; @endphp
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                    {{ match($r->livello_priorita) { 1=>'bg-gray-100 text-gray-600', 3=>'bg-orange-100 text-orange-700', 4=>'bg-red-100 text-red-700', default=>'bg-blue-100 text-blue-700' } }}">
                                    {{ $labels[$r->livello_priorita] ?? $r->livello_priorita }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-gray-700">{{ $r->ore_target }}h</td>
                            <td class="px-4 py-3 text-gray-500">{{ $r->ore_warning }}h</td>
                            <td class="px-4 py-3 text-gray-500 text-xs">{{ $r->descrizione ?? '—' }}</td>
                            <td class="px-4 py-3 text-right flex gap-2 justify-end">
                                <a href="{{ route('admin.sla.edit', $r->id_sla) }}"
                                   class="text-blue-600 hover:text-blue-800 text-xs font-medium">Modifica</a>
                                <form method="POST" action="{{ route('admin.sla.destroy', $r->id_sla) }}"
                                      onsubmit="return confirm('Eliminare questa regola SLA?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="text-red-500 hover:text-red-700 text-xs font-medium">Elimina</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="mt-4 p-4 bg-blue-50 rounded-lg text-sm text-blue-700">
        <strong>Come funziona:</strong> quando una segnalazione viene aperta, il sistema cerca la regola SLA più specifica
        (tipologia + specializzazione + priorità). Se non trovata, usa la regola default (senza tipologia).
        Il job <code>sla:check</code> controlla quotidianamente e notifica i supervisori.
    </div>
</x-app-layout>
