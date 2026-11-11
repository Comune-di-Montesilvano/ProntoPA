{{-- Tipologie che nessun gestore attivo e raggiungibile copre: le loro segnalazioni arrivano solo ai supervisori.
     Parametri: $orfane, $nonVerificati (id tipologia → utenti senza email), $compatto (bool, default false). --}}
@if($orfane->isNotEmpty())
    <section class="pa-card pa-passo pa-passo-amber p-4 flex flex-col gap-2" role="status">
        <p class="m-0 font-semibold text-[var(--ink)]">
            Tipologie senza gestore ({{ $orfane->count() }}): le loro segnalazioni arrivano solo ai supervisori.
        </p>
        @if($compatto ?? false)
            <p class="m-0 text-sm text-[var(--slate-700)]">
                {{ $orfane->pluck('descrizione')->implode(', ') }} ·
                <a href="{{ route('organizzazione.tipologie-gestori.index') }}" class="underline font-medium">assegnale a un gestore</a>
            </p>
        @else
            <ul class="m-0 pl-5 text-sm text-[var(--slate-700)] list-disc">
                @foreach($orfane as $t)
                    <li>
                        {{ $t->descrizione }}
                        <span class="text-[var(--slate-500)]">({{ $t->gruppo?->descrizione }})</span>
                        @foreach($nonVerificati[$t->id_tipologia_segnalazione] ?? [] as $u)
                            <span class="block text-xs text-[var(--slate-500)]">copertura non verificata: {{ $u->username ?? $u->name }} (in attesa del primo accesso)</span>
                        @endforeach
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endif
