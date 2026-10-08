<x-app-layout>
    <x-slot name="header">Deleghe scuole</x-slot>
    <x-slot name="actions">
        <a href="{{ route('admin.deleghe.create') }}"
           class="inline-flex items-center px-3 py-1.5 bg-blue-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">
            + Pre-delega
        </a>
    </x-slot>

    <div class="space-y-6">
        @if($senzaEmail->isNotEmpty())
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm text-amber-800">
                <strong>Scuole senza email</strong> (non possono ricevere richieste di delega):
                {{ $senzaEmail->pluck('descrizione')->implode(', ') }}
            </div>
        @endif

        @if($bloccati->isNotEmpty())
            <div class="bg-white shadow-sm rounded-xl p-4 text-sm">
                <strong>Persone bloccate</strong>
                <ul class="mt-2 space-y-1">
                    @foreach($bloccati as $bloccato)
                        <li class="flex items-center justify-between">
                            <span>{{ $bloccato->name }} · {{ $bloccato->codice_fiscale }} — {{ $bloccato->motivo_blocco }}</span>
                            <form method="POST" action="{{ route('admin.deleghe.sblocca', $bloccato) }}">@csrf<button class="text-xs text-blue-700 hover:underline">Sblocca</button></form>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="GET" class="bg-white shadow-sm rounded-xl p-4 grid grid-cols-1 sm:grid-cols-4 gap-3">
            <select name="stato" class="border-gray-300 rounded-md text-sm">
                <option value="">Tutti gli stati</option>
                @foreach(['richiesta', 'attiva', 'rifiutata', 'revocata', 'scaduta'] as $s)
                    <option value="{{ $s }}" @selected(($filtri['stato'] ?? '') === $s)>{{ $s }}</option>
                @endforeach
            </select>
            <select name="id_istituto" class="border-gray-300 rounded-md text-sm">
                <option value="">Tutte le scuole</option>
                @foreach($istituti as $ist)
                    <option value="{{ $ist->id_istituto }}" @selected((int) ($filtri['id_istituto'] ?? 0) === $ist->id_istituto)>{{ $ist->descrizione }}</option>
                @endforeach
            </select>
            <x-text-input name="q" :value="$filtri['q'] ?? ''" placeholder="Nome o codice fiscale" />
            <button class="inline-flex justify-center items-center px-3 py-1.5 bg-blue-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest">Filtra</button>
        </form>

        <div class="bg-white shadow-sm rounded-xl overflow-x-auto">
            <table class="min-w-full text-sm divide-y divide-gray-100">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase"><tr>
                    <th class="px-3 py-2 text-left">Persona</th><th class="px-3 py-2 text-left">Scuola</th><th class="px-3 py-2 text-left">Per</th>
                    <th class="px-3 py-2 text-left">Stato</th><th class="px-3 py-2 text-left">Scadenza</th><th class="px-3 py-2"></th>
                </tr></thead>
                <tbody class="divide-y divide-gray-50">
                    @forelse($deleghe as $delega)
                        <tr>
                            <td class="px-3 py-2">{{ $delega->user?->name ?? 'non ancora entrato' }}<br><span class="text-xs text-gray-500">{{ $delega->codice_fiscale }}</span></td>
                            <td class="px-3 py-2">{{ $delega->istituto?->descrizione }}</td>
                            <td class="px-3 py-2">{{ $delega->descrizioneAmbito() }}</td>
                            <td class="px-3 py-2">{{ $delega->stato }}@if($delega->stato === 'richiesta' && ! $delega->richiesta_inviata_at) <span class="text-xs text-amber-700">(in coda)</span>@endif</td>
                            <td class="px-3 py-2">{{ $delega->valida_fino_at?->format('d/m/Y') ?? '—' }}</td>
                            <td class="px-3 py-2 text-right space-y-1">
                                @if($delega->stato === 'richiesta')
                                    <form method="POST" action="{{ route('admin.deleghe.attiva', $delega->gruppo_richiesta) }}" class="flex gap-1 justify-end">
                                        @csrf
                                        <input name="motivo" required placeholder="Motivo" class="border-gray-300 rounded text-xs w-32">
                                        <button class="text-xs text-green-700 hover:underline">Attiva</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.deleghe.reinvia', $delega->gruppo_richiesta) }}">@csrf<button class="text-xs text-blue-700 hover:underline">Reinvia email</button></form>
                                @endif
                                @if(in_array($delega->stato, ['attiva', 'richiesta'], true))
                                    <form method="POST" action="{{ route('admin.deleghe.revoca', $delega) }}" class="flex gap-1 justify-end">
                                        @csrf
                                        <input name="motivo" required placeholder="Motivo" class="border-gray-300 rounded text-xs w-32">
                                        <button class="text-xs text-red-700 hover:underline">Revoca</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">Nessuna delega.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $deleghe->links() }}
    </div>
</x-app-layout>
