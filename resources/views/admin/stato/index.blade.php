<x-app-layout>
    <x-slot name="header">Stato del sistema</x-slot>

    @php
        $tono = ['ok' => 'emerald', 'attenzione' => 'amber', 'problema' => 'rose', 'non_verificabile' => 'slate'];
        $etichetta = ['ok' => 'Tutto a posto', 'attenzione' => 'Da guardare', 'problema' => 'Problema', 'non_verificabile' => 'Da verificare'];
        $bordo = ['ok' => 'var(--emerald)', 'attenzione' => 'var(--amber)', 'problema' => 'var(--rose)', 'non_verificabile' => 'var(--slate-300)'];
    @endphp

    <div class="space-y-6 max-w-6xl">
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            @foreach($riquadri as $r)
                <a href="{{ $r->url }}" class="pa-card p-4 flex flex-col gap-2 min-w-0 hover:shadow-md transition"
                   style="border-left:4px solid {{ $bordo[$r->stato] }};">
                    <div class="flex items-start justify-between gap-2">
                        <h2 class="pa-sezione-titolo break-words min-w-0">{{ $r->titolo }}</h2>
                        <x-pa.pill :tono="$tono[$r->stato]">{{ $etichetta[$r->stato] }}</x-pa.pill>
                    </div>
                    <p class="text-sm break-words" style="color:var(--slate-600);">{{ $r->messaggio }}</p>
                </a>
            @endforeach
        </div>

        @if($spente !== [])
            <p class="text-sm" style="color:var(--slate-500);">
                Non attive: {{ implode(', ', $spente) }}.
                <a href="{{ route('admin.integrazioni') }}" class="underline">Vai a Integrazioni</a>
            </p>
        @endif
    </div>
</x-app-layout>
