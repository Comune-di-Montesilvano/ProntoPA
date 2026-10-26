{{-- Notifiche partite per questa segnalazione (solo lettura comune: contiene nomi ed email di terzi). --}}
@php
    $notifiche = $segnalazione->notifiche()->with('utente')->latest('id')->limit(100)->get();
@endphp

<x-pa.card titolo="Notifiche inviate">
    @if($notifiche->isEmpty())
        <p class="m-0 text-sm text-[var(--slate-500)]">Nessuna notifica inviata.</p>
    @else
        <details @if($notifiche->count() <= 5) open @endif>
            <summary class="cursor-pointer text-sm text-[var(--slate-600)]">{{ $notifiche->count() === 100 ? 'Ultime 100' : $notifiche->count() }} {{ $notifiche->count() === 1 ? 'notifica' : 'notifiche' }}</summary>
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
