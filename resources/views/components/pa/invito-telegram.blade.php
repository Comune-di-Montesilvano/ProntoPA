{{-- Invito a collegare Telegram in cima agli elenchi di gestori, operai e ditte (1.0.9).
     "Collega" genera il codice e apre il bot (basta premere Avvia); "Ignora" lo nasconde per 30 giorni. --}}
@if(auth()->user()?->mostraInvitoTelegram())
    <div class="flex flex-wrap items-center gap-3 rounded-lg border border-[var(--slate-200)] bg-[var(--slate-50)] px-4 py-3" role="note">
        <i class="fab fa-telegram text-2xl text-[#229ED9]" aria-hidden="true"></i>
        <div class="min-w-0 flex-1">
            <p class="m-0 text-sm font-semibold text-[var(--ink)]">Ricevi gli avvisi su Telegram</p>
            <p class="m-0 text-xs text-[var(--slate-600)]">Lavori assegnati e scadenze sul telefono, e comandi rapidi come /lista e /oggi. Si collega in un attimo: apri il bot e premi Avvia.</p>
        </div>
        <div class="flex items-center gap-2">
            <form method="POST" action="{{ route('profile.telegram.store') }}" target="_blank">
                @csrf
                <input type="hidden" name="apri" value="1">
                <x-pa.btn type="submit" variant="primary">Collega Telegram</x-pa.btn>
            </form>
            <form method="POST" action="{{ route('profile.telegram.ignora') }}">
                @csrf
                <x-pa.btn type="submit" title="Non mostrarlo per 30 giorni">Ignora</x-pa.btn>
            </form>
        </div>
    </div>
@endif
