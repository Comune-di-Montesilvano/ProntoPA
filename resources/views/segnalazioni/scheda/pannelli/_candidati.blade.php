{{-- Scelta di un'altra segnalazione: proposte automatiche + ricerca per numero o testo (debounce 300 ms).
     Parametri: nome, titolo, urlCandidati, action, campo, nascosti (array), intro, riepilogo, bottone, vuoto. --}}
<x-pa.drawer :name="$nome" :title="$titolo" :sottotitolo="'#'.$segnalazione->id_segnalazione" :aperto="old('_pannello') === $nome">
    <form method="POST" action="{{ $action }}" class="flex flex-1 flex-col"
          x-data="{
              q: '', righe: [], caricando: false, scelta: null, timer: null,
              carica() {
                  this.caricando = true;
                  fetch(@js($urlCandidati) + (this.q ? '?q=' + encodeURIComponent(this.q) : ''), { headers: { 'Accept': 'application/json' } })
                      .then(r => r.ok ? r.json() : [])
                      .then(d => { this.righe = d; this.caricando = false; })
                      .catch(() => { this.righe = []; this.caricando = false; });
              },
              cerca() { clearTimeout(this.timer); this.timer = setTimeout(() => this.carica(), 300); },
          }"
          x-init="carica()">
        @csrf
        <input type="hidden" name="_pannello" value="{{ $nome }}">
        @foreach($nascosti as $chiave => $valore)
            <input type="hidden" name="{{ $chiave }}" value="{{ $valore }}">
        @endforeach
        <input type="hidden" name="{{ $campo }}" :value="scelta || ''">

        <div class="flex flex-1 flex-col gap-4 px-6 py-5">
            <p class="m-0 text-sm text-[var(--slate-700)]">{{ $intro }}</p>
            <input type="search" class="pa-input" placeholder="Cerca per numero o testo…" x-model="q" x-on:input="cerca()" aria-label="Cerca segnalazione">
            <ul class="m-0 flex flex-col gap-1 p-0 list-none" aria-live="polite">
                <template x-for="r in righe" :key="r.id">
                    <li>
                        <button type="button" class="block w-full rounded-md border px-3 py-2 text-left text-sm"
                                :class="scelta === r.id ? 'border-[var(--ente-primary)] bg-[var(--ente-primary-50)]' : 'border-[var(--slate-200)] hover:bg-[var(--slate-50)]'"
                                x-on:click="scelta = r.id" :aria-pressed="scelta === r.id">
                            <span class="pa-mono font-semibold" x-text="'#' + r.id"></span>
                            <strong x-text="r.titolo"></strong>
                            <span class="block text-[var(--slate-500)]" x-text="[r.sede, r.stato, r.data].filter(Boolean).join(' · ')"></span>
                        </button>
                    </li>
                </template>
                <li x-show="caricando" class="text-sm text-[var(--slate-500)]">Ricerca…</li>
                <li x-show="!caricando && righe.length === 0" class="text-sm text-[var(--slate-500)]">{{ $vuoto }}</li>
            </ul>
            <p class="m-0 rounded-md bg-[var(--slate-50)] p-3 text-sm text-[var(--slate-700)]" x-show="scelta">{{ $riepilogo }}</p>
            <x-input-error :messages="$errors->get($campo)" />
        </div>
        <footer class="flex justify-end gap-2 border-t border-[var(--slate-200)] px-6 py-4">
            <x-pa.btn x-on:click="$dispatch('apri-pannello', '')">Annulla</x-pa.btn>
            <x-pa.btn type="submit" variant="primary" ::disabled="!scelta">{{ $bottone }}</x-pa.btn>
        </footer>
    </form>
</x-pa.drawer>
