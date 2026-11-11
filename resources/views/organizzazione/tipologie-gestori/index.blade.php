<x-app-layout>
    <x-slot name="header">Tipologie dei gestori</x-slot>

    <div class="flex flex-col gap-4">
        @if(session('success'))
            <div class="px-4 py-3 bg-green-50 border border-green-200 rounded-lg text-green-800 text-sm">{{ session('success') }}</div>
        @endif

        <p class="m-0 text-sm text-[var(--slate-600)]">
            Ogni gestore vede, smista e riceve gli avvisi delle segnalazioni delle sue tipologie.
            I supervisori vedono tutto e non compaiono qui. I gestori arrivano dal gruppo di dominio
            dei gestori e compaiono dopo il loro primo accesso; gli amministratori compaiono per poter
            seguire anche alcune tipologie.
        </p>

        @include('organizzazione._orfane')

        <x-pa.card titolo="Per gestore">
            @if($gestori->isEmpty())
                <p class="m-0 text-sm text-[var(--slate-500)]">Nessun gestore attivo: aggiungi le persone al gruppo di dominio dei gestori e fai loro fare il primo accesso.</p>
            @else
                <table class="min-w-full divide-y divide-gray-100 text-sm">
                    <tbody class="divide-y divide-gray-50">
                        @foreach($gestori as $g)
                            <tr>
                                <td class="py-2 pr-4 font-medium text-[var(--ink)] align-top">
                                    {{ $g->name }}
                                    @if($g->hasRole('admin'))
                                        <span class="text-xs font-normal text-[var(--slate-500)]">(amministratore)</span>
                                    @endif
                                    @if(blank($g->email))
                                        <span class="block text-xs font-normal text-amber-700">senza email: non riceve avvisi</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-[var(--slate-700)]">
                                    @if($g->gestore_tutte_tipologie && ! $g->hasRole('admin'))
                                        Tutte le tipologie
                                    @elseif($g->tipologieGestite->isEmpty())
                                        <span class="text-amber-700">Nessuna tipologia</span>
                                    @else
                                        {{ $g->tipologieGestite->pluck('descrizione')->implode(', ') }}
                                    @endif
                                </td>
                                <td class="py-2 text-right align-top">
                                    <a href="{{ route('organizzazione.tipologie-gestori.edit', $g) }}" class="text-blue-600 hover:text-blue-800 text-xs font-medium">Modifica</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-pa.card>

        <x-pa.card titolo="Per tipologia">
            <table class="min-w-full divide-y divide-gray-100 text-sm">
                <tbody class="divide-y divide-gray-50">
                    @foreach($tipologie->groupBy(fn ($t) => $t->gruppo?->descrizione ?? 'Senza gruppo') as $gruppo => $voci)
                        <tr><th colspan="2" class="pt-3 pb-1 text-left text-xs uppercase tracking-wider text-[var(--slate-500)]">{{ $gruppo }}</th></tr>
                        @foreach($voci as $t)
                            <tr>
                                <td class="py-1.5 pr-4 text-[var(--ink)]">{{ $t->descrizione }}</td>
                                <td class="py-1.5 text-[var(--slate-700)]">
                                    {{ $copertura[$t->id_tipologia_segnalazione]->pluck('name')->implode(', ') ?: '—' }}
                                </td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </x-pa.card>
    </div>
</x-app-layout>
