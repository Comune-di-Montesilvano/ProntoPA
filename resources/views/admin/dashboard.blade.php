<x-app-layout>
    <x-slot name="header">Amministrazione</x-slot>

    <div class="space-y-6">
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
            <div class="bg-white shadow-sm rounded-lg p-5 border-l-4 border-green-500">
                <div class="text-xs font-semibold uppercase tracking-widest text-gray-500">Utenti attivi</div>
                <div class="mt-2 text-3xl font-bold text-green-600">{{ $conteggi['utenti_attivi'] ?? 0 }}</div>
                <div class="mt-1 text-sm text-gray-500">Account approvati e attivi</div>
            </div>
            <div class="bg-white shadow-sm rounded-lg p-5 border-l-4 border-amber-500">
                <div class="text-xs font-semibold uppercase tracking-widest text-gray-500">Utenti pending</div>
                <div class="mt-2 text-3xl font-bold text-amber-600">{{ $conteggi['utenti_pending'] ?? 0 }}</div>
                <div class="mt-1 text-sm text-gray-500">Richieste in attesa di approvazione</div>
            </div>
            <div class="bg-white shadow-sm rounded-lg p-5 border-l-4 border-red-500">
                <div class="text-xs font-semibold uppercase tracking-widest text-gray-500">Utenti disattivati</div>
                <div class="mt-2 text-3xl font-bold text-red-600">{{ $conteggi['utenti_disattivati'] ?? 0 }}</div>
                <div class="mt-1 text-sm text-gray-500">Account sospesi o dismessi</div>
            </div>
            <div class="bg-white shadow-sm rounded-lg p-5 border-l-4 border-blue-500">
                <div class="text-xs font-semibold uppercase tracking-widest text-gray-500">Enti censiti</div>
                <div class="mt-2 text-3xl font-bold text-blue-600">{{ $conteggi['enti'] ?? 0 }}</div>
                <div class="mt-1 text-sm text-gray-500">Organizzazioni registrate nel backoffice</div>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
            <div class="bg-white shadow-sm rounded-lg p-5">
                <div class="text-xs font-semibold uppercase tracking-widest text-gray-500">Sedi</div>
                <div class="mt-2 text-3xl font-bold text-blue-600">{{ $conteggi['sedi'] ?? 0 }}</div>
                <div class="mt-1 text-sm text-gray-500">Plessi e strutture censite</div>
            </div>
            <div class="bg-white shadow-sm rounded-lg p-5">
                <div class="text-xs font-semibold uppercase tracking-widest text-gray-500">Imprese</div>
                <div class="mt-2 text-3xl font-bold text-blue-600">{{ $conteggi['imprese'] ?? 0 }}</div>
                <div class="mt-1 text-sm text-gray-500">Anagrafica imprese appaltatrici</div>
            </div>
            <div class="bg-white shadow-sm rounded-lg p-5">
                <div class="text-xs font-semibold uppercase tracking-widest text-gray-500">Appalti</div>
                <div class="mt-2 text-3xl font-bold text-blue-600">{{ $conteggi['appalti'] ?? 0 }}</div>
                <div class="mt-1 text-sm text-gray-500">Contratti e CIG registrati</div>
            </div>
            <div class="bg-white shadow-sm rounded-lg p-5">
                <div class="text-xs font-semibold uppercase tracking-widest text-gray-500">SLA</div>
                <div class="mt-2 text-3xl font-bold text-blue-600">{{ $conteggi['sla'] ?? 0 }}</div>
                <div class="mt-1 text-sm text-gray-500">Regole di lavorazione attive</div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="lg:col-span-2 bg-white shadow-sm rounded-xl p-6">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h2 class="text-sm font-semibold uppercase tracking-widest text-gray-500">Accessi rapidi</h2>
                        <p class="text-sm text-gray-500 mt-1">Le sezioni operative più usate dal backoffice</p>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <a href="{{ route('admin.utenti.index') }}" class="bg-gray-50 border border-gray-200 rounded-lg p-4 hover:bg-white hover:shadow-sm transition">
                        <div class="font-semibold text-gray-800">Utenti</div>
                        <div class="text-sm text-gray-500 mt-1">Approva e monitora gli account</div>
                    </a>
                    <a href="{{ route('admin.organizzazioni.index') }}" class="bg-gray-50 border border-gray-200 rounded-lg p-4 hover:bg-white hover:shadow-sm transition">
                        <div class="font-semibold text-gray-800">Organizzazioni</div>
                        <div class="text-sm text-gray-500 mt-1">Anagrafica enti manuale</div>
                    </a>
                    <a href="{{ route('admin.sedi.index') }}" class="bg-gray-50 border border-gray-200 rounded-lg p-4 hover:bg-white hover:shadow-sm transition">
                        <div class="font-semibold text-gray-800">Sedi</div>
                        <div class="text-sm text-gray-500 mt-1">Plessi e strutture collegate</div>
                    </a>
                    <a href="{{ route('admin.sla.index') }}" class="bg-gray-50 border border-gray-200 rounded-lg p-4 hover:bg-white hover:shadow-sm transition">
                        <div class="font-semibold text-gray-800">SLA</div>
                        <div class="text-sm text-gray-500 mt-1">Tempi e priorità di lavorazione</div>
                    </a>
                    <a href="{{ route('admin.impostazioni.index') }}" class="bg-gray-50 border border-gray-200 rounded-lg p-4 hover:bg-white hover:shadow-sm transition">
                        <div class="font-semibold text-gray-800">Impostazioni ente</div>
                        <div class="text-sm text-gray-500 mt-1">Brand, email e policy</div>
                    </a>
                    <a href="{{ route('gestione.dashboard') }}" class="bg-gray-50 border border-gray-200 rounded-lg p-4 hover:bg-white hover:shadow-sm transition">
                        <div class="font-semibold text-gray-800">Gestione segnalazioni</div>
                        <div class="text-sm text-gray-500 mt-1">Dashboard operativa</div>
                    </a>
                </div>
            </div>

            <div class="bg-white shadow-sm rounded-xl p-6">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h2 class="text-sm font-semibold uppercase tracking-widest text-gray-500">Pendenti recenti</h2>
                        <p class="text-sm text-gray-500 mt-1">Ultime richieste registrazione</p>
                    </div>
                    <a href="{{ route('admin.utenti.index', ['solo_pending' => 1]) }}" class="text-xs font-semibold text-blue-600 hover:text-blue-800">Vedi tutti</a>
                </div>

                @if($utentiPendenti->isEmpty())
                    <div class="text-sm text-gray-400 py-8 text-center">Nessuna richiesta in attesa.</div>
                @else
                    <div class="space-y-3">
                        @foreach($utentiPendenti as $utente)
                            <div class="rounded-lg border border-gray-200 p-3">
                                <div class="font-medium text-gray-800">{{ $utente->name }}</div>
                                <div class="text-xs text-gray-500 mt-1">{{ $utente->email }}</div>
                                <div class="text-xs text-gray-500 mt-1">Ente: {{ $utente->istituto?->descrizione ?? '—' }}</div>
                                <div class="mt-3 flex items-center gap-3">
                                    <a href="{{ route('admin.utenti.index', ['solo_pending' => 1]) }}" class="text-xs font-semibold text-blue-600 hover:text-blue-800">Apri coda</a>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
