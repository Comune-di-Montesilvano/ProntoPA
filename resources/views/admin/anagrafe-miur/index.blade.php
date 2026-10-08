<x-app-layout>
    <x-slot name="header">Anagrafe scuole MIUR</x-slot>

    <div class="space-y-6">
        <div class="bg-white shadow-sm rounded-xl p-6 space-y-3">
            <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wider">Dataset</h2>

            @if(! $haIndice)
                <p class="text-sm text-gray-600">Nessuna anagrafe scaricata. Premi "Scarica" per importare il file indicato in Impostazioni.</p>
            @else
                <dl class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
                    <div><dt class="text-gray-500">File in uso</dt><dd class="break-all">{{ $stato['url'] }}</dd></div>
                    <div><dt class="text-gray-500">Scaricato il</dt><dd>{{ $stato['scaricato_at'] ? \Illuminate\Support\Carbon::parse($stato['scaricato_at'])->format('d/m/Y H:i') : '—' }}</dd></div>
                    <div><dt class="text-gray-500">Scuole in anagrafe</dt><dd>{{ $stato['sedi'] ?? '—' }}</dd></div>
                </dl>
            @endif

            @if($stato['stato'] === 'in_corso')
                <p class="text-sm text-blue-700">Download in corso…</p>
            @elseif($stato['stato'] === 'errore')
                <p class="text-sm text-red-700">Ultimo download non riuscito: {{ $stato['messaggio'] }}</p>
            @elseif($stato['messaggio'])
                <p class="text-sm text-gray-600">{{ $stato['messaggio'] }}</p>
            @endif

            @if($nuovoLink)
                <p class="text-sm text-amber-700">Nuovo link configurato, non ancora scaricato: {{ $url }}</p>
            @endif

            <form method="POST" action="{{ route('admin.anagrafe-miur.scarica') }}">
                @csrf
                <button type="submit" @disabled($stato['stato'] === 'in_corso')
                        class="inline-flex items-center px-3 py-1.5 bg-blue-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 disabled:opacity-50 transition">
                    Scarica
                </button>
                <a href="{{ route('admin.impostazioni.index') }}" class="ml-3 text-sm text-blue-700 hover:underline">Cambia link</a>
            </form>
        </div>

        @if($mancanti !== [])
            <div class="bg-white shadow-sm rounded-xl p-6">
                <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wider mb-3">Non più in anagrafe MIUR</h2>
                <p class="text-sm text-gray-600 mb-3">Record allineati al MIUR il cui codice non c'è più nel file in uso (accorpamenti, dimensionamento). Non vengono rimossi: decidi tu.</p>
                <table class="min-w-full text-sm">
                    <thead class="text-xs text-gray-500 uppercase"><tr><th class="text-left py-2">Tipo</th><th class="text-left">Codice</th><th class="text-left">Nome</th><th class="text-right">Segnalazioni</th><th></th></tr></thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach($mancanti as $m)
                            <tr>
                                <td class="py-2">{{ $m['tipo'] === 'istituto' ? 'Istituto' : 'Sede' }}</td>
                                <td>{{ $m['codice'] }}</td>
                                <td>{{ $m['nome'] }}</td>
                                <td class="text-right">{{ $m['segnalazioni'] }}</td>
                                <td class="text-right">
                                    <a class="text-blue-700 hover:underline" href="{{ $m['tipo'] === 'istituto' ? route('admin.organizzazioni.edit', $m['id']) : route('admin.sedi.edit', $m['id']) }}">Apri</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if($haIndice)
            <div class="bg-white shadow-sm rounded-xl p-6 space-y-4">
                <form method="GET" class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <x-text-input name="q" :value="$q" placeholder="Nome o codice meccanografico" />
                    <x-text-input name="comune" :value="$comune" placeholder="Comune (es. MONTESILVANO)" />
                    <x-primary-button>Cerca</x-primary-button>
                </form>

                @if($risultati !== null)
                    @forelse($risultati as $ist)
                        <a href="{{ route('admin.anagrafe-miur.show', $ist['codice']) }}"
                           class="flex items-center justify-between border border-gray-200 rounded-lg p-3 hover:bg-gray-50">
                            <span>
                                <span class="font-medium text-gray-800">{{ $ist['nome'] }}</span>
                                <span class="text-xs text-gray-500">{{ $ist['codice'] }} · {{ count($ist['sedi']) }} sedi</span>
                            </span>
                            @isset($presenti[$ist['codice']])
                                <span class="text-xs px-2 py-0.5 rounded-full bg-green-50 text-green-700">presente in ProntoPA</span>
                            @endisset
                        </a>
                    @empty
                        <p class="text-sm text-gray-500">Nessun istituto trovato.</p>
                    @endforelse
                @endif
            </div>
        @endif
    </div>
</x-app-layout>
