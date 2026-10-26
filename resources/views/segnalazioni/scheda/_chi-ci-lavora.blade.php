{{-- Chi ha il lavoro: squadra, operatore, ditta e appalto. Il caposquadra può affidarlo a un membro. --}}
@php
    $squadra = $segnalazione->squadraAssegnata;
    $operatore = $segnalazione->id_operatore_assegnato ? $segnalazione->operatore : null;
    $appalto = $segnalazione->id_appalto ? $segnalazione->appalto : null;
    $caposquadra = $squadra && $squadra->id_caposquadra === auth()->id() && ! $segnalazione->isChiusa();
@endphp
<div class="flex flex-col gap-2 text-sm text-[var(--slate-700)]">
    @if($squadra)
        <p class="m-0"><span class="text-[var(--slate-500)]">Squadra</span> <strong class="text-[var(--ink)]">{{ $squadra->nome }}</strong></p>
    @endif
    @if($operatore)
        <p class="m-0"><span class="text-[var(--slate-500)]">Operatore</span> <strong class="text-[var(--ink)]">{{ $operatore->name }}</strong></p>
    @endif
    @if($appalto)
        <p class="m-0"><span class="text-[var(--slate-500)]">Ditta</span> <strong class="text-[var(--ink)]">{{ $appalto->impresa?->ragione_sociale ?? '—' }}</strong></p>
        <p class="m-0 text-[var(--slate-500)]">Appalto: {{ $appalto->descrizione }}@if($appalto->valido === false) (scaduto)@endif</p>
    @endif
    @if(! $squadra && ! $operatore && ! $appalto)
        <p class="m-0 text-[var(--slate-500)]">Da assegnare</p>
    @endif

    @if($caposquadra && ! $segnalazione->id_operatore_assegnato)
        @php $membri = $squadra->membri()->orderBy('name')->get(); @endphp
        @if($membri->isNotEmpty())
            <form method="POST" action="{{ route('operaio.smista', $segnalazione->id_segnalazione) }}" class="mt-1 flex flex-col gap-2 sm:flex-row">
                @csrf
                <label for="id_membro" class="sr-only">Affida a un membro</label>
                <select id="id_membro" name="id_membro" class="pa-select" required>
                    <option value="">Affida a un membro…</option>
                    @foreach($membri as $membro)
                        <option value="{{ $membro->id }}">{{ $membro->name }}</option>
                    @endforeach
                </select>
                <x-pa.btn type="submit" size="sm">Affida</x-pa.btn>
            </form>
        @endif
    @endif
</div>
