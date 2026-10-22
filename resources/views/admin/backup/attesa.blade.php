<x-app-layout>
    <x-slot name="header">Ripristino in corso</x-slot>

    {{-- Durante il ripristino l'app è in manutenzione: questa pagina non si ricarica,
         il polling usa solo l'HTML già caricato e l'endpoint pubblico dello stato. --}}
    <div class="space-y-6" x-data="attesaRipristino(@js(route('ripristino.stato', $token)), @js(route('login')))">
        <div class="bg-white shadow-sm rounded-xl p-6 space-y-3">
            <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wider">Stato</h2>
            <p class="text-sm text-gray-700">Fase: <strong x-text="fase">in coda</strong></p>
            <p class="text-sm text-gray-600" x-show="messaggio" x-text="messaggio" x-cloak></p>

            <p class="text-sm text-blue-700" x-show="esito === null">Non chiudere questa pagina: puoi aspettare qui. Il ripristino può richiedere diversi minuti.</p>
            <div x-show="esito === true" x-cloak class="rounded-lg border border-green-300 bg-green-50 p-3 text-sm text-green-800">
                Ripristino completato. Le sessioni sono state chiuse.
                <a :href="login" class="font-semibold underline">Accedi</a>
            </div>
            <div x-show="esito === false" x-cloak class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-800">
                Ripristino non riuscito.
                <span x-show="preRipristino">Backup dello stato precedente: <strong x-text="preRipristino"></strong>.</span>
                <a :href="login" class="font-semibold underline">Accedi</a>
            </div>
            <p class="text-sm text-amber-700" x-show="offline" x-cloak>Il server non risponde (può essere un riavvio): riprovo ogni 3 secondi.</p>
        </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('attesaRipristino', (url, login) => ({
                login, fase: 'in coda', messaggio: '', esito: null, preRipristino: '', offline: false, timer: null,
                init() { this.controlla(); this.timer = setInterval(() => this.controlla(), 3000); },
                async controlla() {
                    try {
                        const r = await fetch(url, { headers: { 'Accept': 'application/json' }, cache: 'no-store', redirect: 'manual' });
                        if (!r.ok) { this.offline = true; return; }
                        const d = await r.json().catch(() => null);
                        if (!d) { this.offline = true; return; }
                        this.offline = false;
                        this.fase = d.fase; this.messaggio = d.messaggio ?? ''; this.preRipristino = d.pre_ripristino ?? '';
                        if (d.esito !== null && d.esito !== undefined) { this.esito = d.esito; clearInterval(this.timer); }
                    } catch (e) { this.offline = true; }
                },
            }));
        });
    </script>
</x-app-layout>
