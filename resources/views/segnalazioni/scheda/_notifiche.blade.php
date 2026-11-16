{{-- Avvisi partiti per questa segnalazione (solo lettura comune: contiene nomi ed email di terzi). --}}
@php
    $riepilogo = \App\Models\NotificaSegnalazione::riepilogoPer($segnalazione);
    $quando = fn ($d) => $d?->isToday() ? 'oggi '.$d->format('H:i') : $d?->locale('it')->isoFormat('ddd D, HH:mm');
@endphp

<x-pa.card titolo="Avvisi inviati">
    @if($riepilogo === [])
        <p class="m-0 text-sm text-[var(--slate-500)]">Nessun avviso inviato.</p>
    @else
        <dl class="m-0 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-sm">
            @foreach($riepilogo as $r)
                <dt class="text-[var(--slate-500)]">{{ $r['a'] }}</dt>
                <dd class="m-0">{{ $r['quante'] }} {{ $r['solo_email'] ? 'email' : ($r['quante'] === 1 ? 'avviso' : 'avvisi') }} · {{ $r['quante'] === 1 ? '' : 'ultima ' }}{{ $quando($r['ultima']) }}</dd>
            @endforeach
        </dl>
        @php $notifiche = $segnalazione->notifiche()->with('utente')->latest('id')->limit(100)->get(); @endphp
        <details>
            <summary class="cursor-pointer text-sm font-semibold text-[var(--ente-primary)]">Vedi tutti</summary>
            <ul class="m-0 mt-2 flex list-none flex-col gap-2 p-0 text-sm">
                @foreach($notifiche as $n)
                    <li class="flex flex-col border-b border-[var(--slate-100)] pb-2 last:border-0">
                        <span class="font-medium text-[var(--ink)]">{{ $n->etichetta() }}</span>
                        <span class="text-[var(--slate-600)] break-words">
                            a {{ $n->utente?->name ?? $n->destinatario }}
                            @if($n->utente?->email) <span class="text-[var(--slate-500)]">({{ $n->utente->email }})</span>@endif
                        </span>
                        <span class="text-xs text-[var(--slate-500)]">{{ $n->created_at?->format('d/m/Y H:i') }} · {{ $n->canaleLeggibile() }}</span>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
</x-pa.card>
