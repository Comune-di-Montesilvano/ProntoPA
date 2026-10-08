@php
    $prima = $righe->first();
    $inAttesa = $righe->where('stato', \App\Models\Delega::RICHIESTA);
    $visibili = $inAttesa->isNotEmpty() ? $inAttesa : $righe;
    $ambito = $visibili->contains(fn ($d) => $d->id_plesso === null)
        ? "tutto l'istituto"
        : $visibili->map(fn ($d) => $d->plesso?->nome)->implode(', ');
    // Esito dallo storico: lo stato attuale può essere cambiato dopo (revoca, scadenza).
    $esito = $righe->contains(fn ($d) => $d->storico->contains('evento', 'approvata'))
        ? 'approvata'
        : ($righe->contains('stato', \App\Models\Delega::RIFIUTATA) ? 'rifiutata' : 'annullata');
@endphp
<x-guest-layout>
    <h1 class="text-lg font-semibold text-gray-900">Richiesta di delega</h1>

    <dl class="mt-4 space-y-2 text-sm">
        <div><dt class="text-gray-500">Persona</dt><dd class="font-medium">{{ $prima->user?->name }}</dd></div>
        <div><dt class="text-gray-500">Codice fiscale</dt><dd>{{ $prima->codice_fiscale }}</dd></div>
        <div><dt class="text-gray-500">Email</dt><dd>{{ $prima->user?->email }}</dd></div>
        <div><dt class="text-gray-500">Scuola</dt><dd>{{ $prima->istituto?->descrizione }}</dd></div>
        <div><dt class="text-gray-500">Per</dt><dd>{{ $ambito }}</dd></div>
    </dl>

    @if($inAttesa->isNotEmpty())
        <p class="mt-4 text-sm text-gray-700">Se questa persona lavora nella vostra scuola ed è autorizzata a segnalare guasti, approvate. Se non la conoscete, rifiutate.</p>

        <form method="POST" action="{{ request()->fullUrl() }}" class="mt-6 space-y-4">
            @csrf
            <button type="submit" name="azione" value="approva"
                    class="w-full inline-flex justify-center px-4 py-2 bg-green-600 rounded-md font-semibold text-sm text-white hover:bg-green-700">
                Approva
            </button>
            <div class="border-t border-gray-200 pt-4 space-y-2">
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="non_conosco" value="1" class="rounded border-gray-300">
                    Non conosco questa persona
                </label>
                <button type="submit" name="azione" value="rifiuta"
                        class="w-full inline-flex justify-center px-4 py-2 bg-white border border-red-300 rounded-md font-semibold text-sm text-red-700 hover:bg-red-50">
                    Rifiuta
                </button>
            </div>
        </form>
    @else
        <p class="mt-6 text-sm text-gray-700">
            Richiesta già gestita il {{ $righe->max('decisa_at')?->format('d/m/Y') }}:
            <strong>{{ $esito }}</strong>.
        </p>
    @endif
</x-guest-layout>
