{{-- Collegate: madre (se accorpata), accorpate qui, riparazione precedente (guasto ricorrente), ricomparse successive. --}}
@php $precedente = $segnalazione->riparazionePrecedente(); @endphp
@if($segnalazione->segnalazioneMadre || $segnalazione->duplicati->isNotEmpty() || $precedente || $segnalazione->correlate->isNotEmpty())
    <x-pa.card titolo="Collegate">
        <ul class="m-0 p-0 list-none flex flex-col gap-2 text-sm">
            @if($madre = $segnalazione->segnalazioneMadre)
                <li>Accorpata alla
                    <a class="font-semibold text-[var(--ente-primary)] underline" href="{{ route('segnalazioni.show', $madre->id_segnalazione) }}">#{{ $madre->id_segnalazione }}</a>
                    <span class="text-[var(--slate-500)]">· {{ $madre->statoEnum()->etichetta() }}</span>
                </li>
            @endif
            @if($precedente)
                <li class="pa-testo-amber">
                    Stesso problema riparato il {{ $precedente['chiusa_il']->format('d/m/Y') }} da {{ $precedente['da'] }}, {{ $precedente['giorni'] }} giorni fa:
                    <a class="font-semibold underline" href="{{ route('segnalazioni.show', $precedente['id']) }}">#{{ $precedente['id'] }}</a>
                </li>
            @endif
            @foreach($segnalazione->duplicati as $dup)
                <li>Accorpata qui:
                    <a class="font-semibold text-[var(--ente-primary)] underline" href="{{ route('segnalazioni.show', $dup->id_segnalazione) }}">#{{ $dup->id_segnalazione }}</a>
                    <span class="text-[var(--slate-500)]">del {{ $dup->data_segnalazione?->format('d/m/Y') }}</span>
                </li>
            @endforeach
            @foreach($segnalazione->correlate as $cor)
                <li>Si è ripresentato il {{ $cor->data_segnalazione?->format('d/m/Y') }}:
                    <a class="font-semibold text-[var(--ente-primary)] underline" href="{{ route('segnalazioni.show', $cor->id_segnalazione) }}">#{{ $cor->id_segnalazione }}</a>
                    <span class="text-[var(--slate-500)]">· {{ $cor->statoEnum()->etichetta() }}</span>
                </li>
            @endforeach
        </ul>
    </x-pa.card>
@endif
