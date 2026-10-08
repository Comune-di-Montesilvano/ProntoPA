<x-app-layout>
    <x-slot name="header">Le mie deleghe</x-slot>

    <div class="space-y-6">
        <div class="bg-white shadow-sm rounded-xl p-6">
            <p class="text-sm text-gray-700">Per segnalare guasti per conto di una scuola serve una delega confermata dalla sua segreteria. La delega vale 12 mesi e la segreteria la rinnova ogni anno.</p>

            @if($deleghe->isEmpty())
                <p class="mt-4 text-sm text-gray-500">Non hai ancora deleghe.</p>
            @else
                <table class="mt-4 min-w-full text-sm divide-y divide-gray-100">
                    <thead class="text-xs text-gray-500 uppercase"><tr><th class="text-left py-2">Scuola</th><th class="text-left">Per</th><th class="text-left">Stato</th><th class="text-left">Scadenza</th><th></th></tr></thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach($deleghe as $delega)
                            <tr>
                                <td class="py-2">{{ $delega->istituto?->descrizione }}</td>
                                <td>{{ $delega->descrizioneAmbito() }}</td>
                                <td>{{ $delega->stato === 'richiesta' ? 'in attesa della segreteria' : $delega->stato }}</td>
                                <td>{{ $delega->valida_fino_at?->format('d/m/Y') ?? '—' }}</td>
                                <td class="text-right">
                                    @if(in_array($delega->stato, ['attiva', 'richiesta'], true))
                                        <form method="POST" action="{{ route('scuola.deleghe.rinuncia', $delega) }}"
                                              onsubmit="return confirm('Chiudere questa delega?')">
                                            @csrf
                                            <button class="text-xs text-red-700 hover:underline">{{ $delega->stato === 'attiva' ? 'Rinuncia' : 'Annulla richiesta' }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="bg-white shadow-sm rounded-xl p-6 space-y-4">
            <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wider">Richiedi una delega</h2>
            <form method="GET" class="flex gap-3">
                <x-text-input name="q" :value="$q" class="flex-1" placeholder="Nome della scuola o codice meccanografico" />
                <x-primary-button>Cerca</x-primary-button>
            </form>
            @if($q !== '')
                @forelse($istituti as $istituto)
                    <a href="{{ route('scuola.deleghe.create', $istituto) }}" class="block border border-gray-200 rounded-lg p-3 hover:bg-gray-50">
                        <span class="font-medium text-gray-800">{{ $istituto->descrizione }}</span>
                        <span class="text-xs text-gray-500">{{ $istituto->codice_meccanografico }}</span>
                    </a>
                @empty
                    <p class="text-sm text-gray-500">Nessuna scuola trovata. Se la tua scuola non c'è, contatta l'ente.</p>
                @endforelse
            @endif
        </div>
    </div>
</x-app-layout>
