<x-app-layout>
    <x-slot name="header">Scadenze</x-slot>
    <x-slot name="actions">
        <a href="{{ route('admin.sla.create') }}"
           class="inline-flex items-center px-3 py-1.5 bg-blue-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">
            + Nuova regola
        </a>
    </x-slot>

    <h2 class="text-sm font-semibold text-gray-800 mb-2">Tempi massimi per tipo di segnalazione</h2>
    <div class="bg-white shadow-sm rounded-xl overflow-hidden">
        @if($regole->isEmpty())
            <div class="p-10 text-center text-gray-400 text-sm">
                Nessuna regola configurata. Le segnalazioni non avranno scadenza automatica.
            </div>
        @else
            <table class="min-w-full divide-y divide-gray-100 text-sm">
                <thead class="bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wider">
                    <tr>
                        <th class="px-4 py-3 text-left">Tipologia</th>
                        <th class="px-4 py-3 text-left">Priorità</th>
                        <th class="px-4 py-3 text-left">Tempo massimo</th>
                        <th class="px-4 py-3 text-left">A rischio da</th>
                        <th class="px-4 py-3 text-left">Se ferma</th>
                        <th class="px-4 py-3 text-left">Descrizione</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @foreach($regole as $r)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-gray-700">{{ $r->tipologia?->descrizione ?? '— Tutte —' }}</td>
                            <td class="px-4 py-3">
                                @php $labels = [1=>'Bassa',2=>'Media',3=>'Alta',4=>'Critica']; @endphp
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                    {{ match($r->livello_priorita) { 1=>'bg-gray-100 text-gray-600', 3=>'bg-orange-100 text-orange-700', 4=>'bg-red-100 text-red-700', default=>'bg-blue-100 text-blue-700' } }}">
                                    {{ $labels[$r->livello_priorita] ?? $r->livello_priorita }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-gray-700">{{ $r->descrizioneTempo() }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ $r->ore_warning }} h lav.</td>
                            <td class="px-4 py-3 text-gray-500 text-xs">
                                @if($r->giorni_avviso_inattivita)
                                    avviso dopo {{ $r->giorni_avviso_inattivita }} g lav.{{ $r->giorni_archiviazione_inattivita ? ', archiviata dopo altri '.$r->giorni_archiviazione_inattivita : '' }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-500 text-xs">{{ $r->descrizione ?? '—' }}</td>
                            <td class="px-4 py-3 text-right flex gap-2 justify-end">
                                <a href="{{ route('admin.sla.edit', $r->id_sla) }}"
                                   class="text-blue-600 hover:text-blue-800 text-xs font-medium">Modifica</a>
                                <form method="POST" action="{{ route('admin.sla.destroy', $r->id_sla) }}"
                                      onsubmit="return confirm('Eliminare questa regola?')">
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

    <div class="mt-4 mb-8 p-4 bg-blue-50 rounded-lg text-sm text-blue-700">
        <strong>Come funziona:</strong> quando arriva una segnalazione, il sistema cerca la regola più specifica
        (tipologia e priorità). Se non ne trova, usa quella senza tipologia.
        Ogni giorno controlla le scadenze e avvisa i supervisori.
    </div>

    <form method="POST" action="{{ route('admin.regole.orario') }}" class="bg-white shadow-sm rounded-xl p-5 space-y-4">
        @csrf @method('PATCH')
        <h2 class="text-sm font-semibold text-gray-800">Orario d'ufficio</h2>
        <p class="text-sm text-gray-600">Le scadenze contano solo queste ore. Weekend, festivi nazionali (Pasqua e Pasquetta comprese) e giorno del patrono sono esclusi; la pausa pranzo non si conta. Se cambi orario o patrono, le scadenze delle segnalazioni aperte si ricalcolano.</p>

        <div class="grid gap-4 sm:grid-cols-2">
            <x-pa.impostazione chiave="orario_lun" tipo="orario" etichetta="Lunedì" />
            <x-pa.impostazione chiave="orario_mar" tipo="orario" etichetta="Martedì" />
            <x-pa.impostazione chiave="orario_mer" tipo="orario" etichetta="Mercoledì" />
            <x-pa.impostazione chiave="orario_gio" tipo="orario" etichetta="Giovedì" />
            <x-pa.impostazione chiave="orario_ven" tipo="orario" etichetta="Venerdì" />
            <x-pa.impostazione chiave="orario_sab" tipo="orario" etichetta="Sabato" />
            <x-pa.impostazione chiave="orario_dom" tipo="orario" etichetta="Domenica" />
            <x-pa.impostazione chiave="patrono" tipo="patrono" etichetta="Giorno del patrono"
                aiuto="Giorno e mese (es. 13/06): è considerato festivo." />
        </div>

        <div class="rounded border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700">
            <p class="font-medium">Festivi {{ $anno }}</p>
            <p class="mt-0.5 text-xs text-gray-600">{{ $festivi }}</p>
        </div>

        <button type="submit" class="inline-flex items-center px-4 py-2 bg-blue-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">Salva orario</button>
    </form>
</x-app-layout>
