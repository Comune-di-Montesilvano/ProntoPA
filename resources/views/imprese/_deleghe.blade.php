{{-- Persone che gestiscono la ditta: entrano con SPID/CIE grazie a questa delega. --}}
@php
    $attive = $impresa->deleghe()->where('stato', \App\Models\DelegaImpresa::ATTIVA)->with(['user', 'creataDa'])->latest()->get();
    $revocate = $impresa->deleghe()->where('stato', \App\Models\DelegaImpresa::REVOCATA)->with('revocataDa')->latest('revocata_at')->get();
@endphp
<div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 space-y-4">
    <div>
        <h3 class="text-base font-semibold text-gray-900">Persone che gestiscono la ditta (SPID/CIE)</h3>
        <p class="text-sm text-gray-500 mt-1">
            La persona entra da <span class="font-mono">{{ route('accesso') }}</span> con il proprio SPID o CIE e vede le segnalazioni assegnate a questa ditta,
            anche quelle dei vecchi appalti. Usa sempre la ditta già presente in anagrafica, non crearne una doppia.
        </p>
    </div>

    @if($attive->isEmpty())
        <p class="text-sm text-gray-400">Nessuna persona delegata.</p>
    @else
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Codice fiscale</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Persona</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Delegata da</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($attive as $d)
                    <tr>
                        <td class="px-3 py-2 font-mono text-xs">{{ $d->codice_fiscale }}</td>
                        <td class="px-3 py-2">{{ $d->user?->name ?? 'in attesa del primo accesso' }}</td>
                        <td class="px-3 py-2 text-gray-500">{{ $d->creataDa?->name ?? '—' }} · {{ $d->created_at?->format('d/m/Y') }}</td>
                        <td class="px-3 py-2 text-right">
                            <form method="POST" action="{{ route('imprese.deleghe.destroy', [$impresa->id_impresa, $d->id]) }}" class="inline"
                                  onsubmit="return confirm('Revocare la delega a {{ $d->codice_fiscale }}? Perderà subito l\'accesso.')">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-red-600 hover:text-red-800 text-xs font-medium">Revoca</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if($impresa->attiva !== false)
        <form method="POST" action="{{ route('imprese.deleghe.store', $impresa->id_impresa) }}" class="flex flex-wrap items-end gap-3 pt-2 border-t border-gray-100">
            @csrf
            <div>
                <x-input-label for="codice_fiscale" value="Codice fiscale della persona" />
                <x-text-input id="codice_fiscale" name="codice_fiscale" class="mt-1 block w-56 font-mono uppercase" maxlength="16" :value="old('codice_fiscale')" required />
            </div>
            <div class="flex-1 min-w-[12rem]">
                <x-input-label for="motivo" value="Motivo (facoltativo)" />
                <x-text-input id="motivo" name="motivo" class="mt-1 block w-full" maxlength="255" :value="old('motivo')" placeholder="Es. appalto manutenzione 2026" />
            </div>
            <button type="submit" class="inline-flex items-center px-4 py-2 bg-blue-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700">
                Aggiungi persona
            </button>
            <x-input-error :messages="$errors->get('codice_fiscale')" class="w-full" />
        </form>
    @endif

    @if($revocate->isNotEmpty())
        <details class="text-sm text-gray-500">
            <summary class="cursor-pointer">Deleghe revocate ({{ $revocate->count() }})</summary>
            <ul class="mt-2 space-y-1">
                @foreach($revocate as $d)
                    <li><span class="font-mono text-xs">{{ $d->codice_fiscale }}</span> — revocata {{ $d->revocata_at?->format('d/m/Y') }} da {{ $d->revocataDa?->name ?? '—' }}{{ $d->motivo ? ' ('.$d->motivo.')' : '' }}</li>
                @endforeach
            </ul>
        </details>
    @endif

    <div class="pt-2 border-t border-gray-100">
        @if($impresa->attiva !== false)
            <form method="POST" action="{{ route('imprese.disattiva', $impresa->id_impresa) }}"
                  onsubmit="return confirm('Disattivare la ditta? Le persone delegate perderanno subito l\'accesso.')">
                @csrf
                <button type="submit" class="text-amber-700 hover:text-amber-900 text-sm font-medium">Disattiva ditta</button>
            </form>
        @else
            <form method="POST" action="{{ route('imprese.riattiva', $impresa->id_impresa) }}" class="flex items-center gap-3">
                @csrf
                <span class="text-sm text-gray-600">Ditta disattivata: nessuna persona può accedere.</span>
                <button type="submit" class="text-green-700 hover:text-green-900 text-sm font-medium">Riattiva</button>
            </form>
        @endif
    </div>
</div>
