<x-guest-layout>
    <h1 class="text-lg font-semibold text-gray-900">Rinnovo deleghe — {{ $righe->first()->istituto?->descrizione }}</h1>
    <p class="mt-2 text-sm text-gray-600">Per ogni persona indicate se lavora ancora con voi ed è autorizzata a segnalare guasti. Le deleghe non confermate scadono alla data indicata.</p>

    <table class="mt-4 w-full text-sm divide-y divide-gray-100">
        <thead class="text-xs text-gray-500 uppercase"><tr><th class="text-left py-2">Persona</th><th class="text-left">Per</th><th class="text-left">Scadenza</th><th></th></tr></thead>
        <tbody class="divide-y divide-gray-50">
            @foreach($righe as $delega)
                <tr>
                    <td class="py-2">{{ $delega->user?->name }}</td>
                    <td>{{ $delega->descrizioneAmbito() }}</td>
                    <td>{{ $delega->valida_fino_at?->format('d/m/Y') }}</td>
                    <td class="text-right">
                        @if($delega->stato === 'attiva' && $delega->rinnovo_inviato_at !== null)
                            <form method="POST" action="{{ request()->fullUrl() }}" class="inline-flex gap-2">
                                @csrf
                                <input type="hidden" name="delega" value="{{ $delega->id }}">
                                <button name="azione" value="conferma" class="px-2 py-1 bg-green-600 text-white rounded text-xs">Conferma</button>
                                <button name="azione" value="revoca" class="px-2 py-1 border border-red-300 text-red-700 rounded text-xs">Revoca</button>
                            </form>
                        @elseif($delega->stato === 'attiva')
                            <span class="text-green-700">Confermata</span>
                        @else
                            <span class="text-gray-600">{{ ucfirst($delega->stato) }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</x-guest-layout>
