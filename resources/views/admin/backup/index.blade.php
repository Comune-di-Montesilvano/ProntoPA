<x-app-layout>
    <x-slot name="header">Backup e ripristino</x-slot>

    <div class="space-y-6">
        @if(session('status'))
            <div class="rounded-lg border border-green-300 bg-green-50 p-3 text-sm text-green-800">{{ session('status') }}</div>
        @endif
        @if(session('errore'))
            <div class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-800">{{ session('errore') }}</div>
        @endif

        @if($passphraseMancante)
            <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" role="alert">
                <strong>Backup disattivato.</strong> Imposta BACKUP_PASSPHRASE nella stack: senza, i backup notturni
                non partono e non si possono creare, caricare o ripristinare file su un'installazione con dati.
            </div>
        @endif

        <div class="bg-white shadow-sm rounded-xl p-6 space-y-3">
            <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wider">Nuovo backup</h2>
            <p class="text-sm text-gray-600">Database e allegati in un unico file cifrato. Il backup notturno gira in automatico; qui puoi lanciarne uno subito.</p>
            <form method="POST" action="{{ route('admin.backup.crea') }}">
                @csrf
                <button type="submit" @disabled($passphraseMancante)
                        class="inline-flex items-center px-3 py-1.5 bg-blue-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 disabled:opacity-50 transition">
                    Backup ora
                </button>
            </form>
        </div>

        <div class="bg-white shadow-sm rounded-xl p-6">
            <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wider mb-3">Backup disponibili</h2>
            @if($backups->isEmpty())
                <p class="text-sm text-gray-600">Nessun backup presente.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-xs text-gray-500 uppercase">
                            <tr>
                                <th class="text-left py-2">Data</th>
                                <th class="text-left">Tipo</th>
                                <th class="text-left">Versione</th>
                                <th class="text-right">Dimensione</th>
                                <th class="text-left pl-4">Stato</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @foreach($backups as $b)
                                @php $fallito = $b->dimensione === 0; @endphp
                                <tr>
                                    <td class="py-2 whitespace-nowrap" title="{{ $b->nome }}">{{ $b->creatoIl->format('d/m/Y H:i') }}</td>
                                    <td>{{ $b->tipo }}</td>
                                    <td>{{ $b->versione }}</td>
                                    <td class="text-right whitespace-nowrap">{{ $fallito ? '—' : \Illuminate\Support\Number::fileSize($b->dimensione) }}</td>
                                    <td class="pl-4">
                                        @if($fallito)
                                            <span class="text-red-700">Fallito{{ $b->errore ? ': '.$b->errore : '' }}</span>
                                        @elseif($b->verificato)
                                            <span class="text-green-700">Verificato</span>
                                        @else
                                            <span class="text-amber-700">Non verificato</span>
                                        @endif
                                    </td>
                                    <td class="text-right whitespace-nowrap">
                                        @unless($fallito)
                                            <a class="text-blue-700 hover:underline" href="{{ route('admin.backup.scarica', $b->nome) }}">Scarica</a>
                                            <a class="ml-3 text-blue-700 hover:underline" href="{{ route('admin.backup.anteprima', $b->nome) }}">Ripristina</a>
                                        @endunless
                                        <form method="POST" action="{{ route('admin.backup.elimina', $b->nome) }}" class="inline"
                                              onsubmit="return confirm('Eliminare definitivamente questo backup?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="ml-3 text-red-700 hover:underline">Elimina</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="bg-white shadow-sm rounded-xl p-6 space-y-3"
             x-data="caricaBackup({ url: @js(route('admin.backup.carica')), blocco: @js($dimensioneBlocco), indice: @js(route('admin.backup.index')) })">
            <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wider">Carica un backup</h2>
            <p class="text-sm text-gray-600">Per ripristinare un file scaricato da un'altra installazione (o dopo un disastro). Se è cifrato con una passphrase diversa da quella di questa installazione, indicala: il file verrà ricifrato con quella locale.</p>
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs text-gray-500 mb-1" for="file-backup">File .zip</label>
                    <input id="file-backup" type="file" accept=".zip" x-ref="file" class="text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1" for="pass-backup">Passphrase del file (se diversa)</label>
                    <input id="pass-backup" type="password" x-model="passphrase" autocomplete="off" class="rounded-md border-gray-300 text-sm">
                </div>
                <button type="button" @click="carica()" :disabled="inCorso"
                        class="inline-flex items-center px-3 py-1.5 bg-blue-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 disabled:opacity-50 transition">
                    Carica
                </button>
            </div>
            <div x-show="inCorso" class="w-full bg-gray-100 rounded h-2" x-cloak>
                <div class="bg-blue-600 h-2 rounded" :style="`width: ${percentuale}%`"></div>
            </div>
            <p x-show="messaggio" x-text="messaggio" class="text-sm" :class="errore ? 'text-red-700' : 'text-green-700'" x-cloak></p>
        </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('caricaBackup', (cfg) => ({
                passphrase: '', inCorso: false, percentuale: 0, messaggio: '', errore: false,
                async carica() {
                    const file = this.$refs.file.files[0];
                    if (!file) { this.messaggio = 'Scegli un file.'; this.errore = true; return; }
                    const totale = Math.max(1, Math.ceil(file.size / cfg.blocco));
                    const id = crypto.randomUUID();
                    const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                    this.inCorso = true; this.errore = false; this.messaggio = ''; this.percentuale = 0;
                    try {
                        for (let i = 0; i < totale; i++) {
                            const dati = new FormData();
                            dati.append('upload_id', id);
                            dati.append('indice', i);
                            dati.append('totale', totale);
                            dati.append('blocco', file.slice(i * cfg.blocco, (i + 1) * cfg.blocco), 'blocco');
                            if (i === totale - 1) dati.append('passphrase', this.passphrase);
                            const r = await fetch(cfg.url, {
                                method: 'POST', body: dati,
                                headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
                            });
                            const esito = await r.json().catch(() => ({}));
                            if (!r.ok) throw new Error(esito.errore ?? esito.message ?? 'Caricamento non riuscito.');
                            this.percentuale = Math.round(((i + 1) / totale) * 100);
                            if (i === totale - 1) {
                                this.messaggio = 'Backup caricato e verificato: ' + esito.nome;
                                setTimeout(() => window.location.href = cfg.indice, 1200);
                            }
                        }
                    } catch (e) {
                        this.errore = true; this.messaggio = e.message;
                    } finally {
                        this.inCorso = false; this.passphrase = '';
                    }
                },
            }));
        });
    </script>
</x-app-layout>
