{{-- Elenco nella lettura segnalante (dashboard segnalatore e segnalazioni.index): niente priorità né assegnatario. --}}
<div class="pa-card overflow-hidden">
    @if($segnalazioni->isEmpty())
        <div class="flex flex-col items-center gap-3 p-10 text-center">
            <p class="m-0 text-sm text-[var(--slate-500)]">{{ $vuoto }}</p>
            <x-pa.btn variant="primary" :href="route('segnalazioni.create')">Inserisci una segnalazione</x-pa.btn>
        </div>
    @else
        <table class="pa-elenco">
            <thead>
                <tr>
                    <th scope="col">Segnalazione</th>
                    <th scope="col">Stato</th>
                    <th scope="col">Aggiornata</th>
                </tr>
            </thead>
            <tbody>
                @foreach($segnalazioni as $s)
                    @include('segnalazioni._riga', [
                        'colonne' => ['segnalazione', 'stato', 'aggiornata'],
                        'lettura' => 'segnalante',
                        'segui' => in_array((int) $s->id_segnalazione, $seguite, true),
                    ])
                @endforeach
            </tbody>
        </table>
        @if($segnalazioni->hasPages())
            <div class="border-t border-[var(--slate-100)] px-4 py-3">{{ $segnalazioni->links() }}</div>
        @endif
    @endif
</div>
