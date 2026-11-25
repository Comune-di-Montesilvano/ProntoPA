{{-- Dove: minimappa, sede, indirizzo, ubicazione, telefono della sede. La lettura lavoro aggiunge Naviga e Chiama. --}}
@php
    $conAzioni = $conAzioni ?? false;
    $conMappa = $conMappa ?? false;
    $plesso = $segnalazione->id_plesso ? $segnalazione->plesso : null;
    $posizione = $segnalazione->posizione();
    $haCoordinate = $posizione !== null;
    $naviga = $haCoordinate
        ? 'https://www.google.com/maps/search/?api=1&query='.$posizione['lat'].','.$posizione['lng']
        : ($plesso?->indirizzo ? 'https://www.google.com/maps/search/?api=1&query='.urlencode($plesso->indirizzo) : null);
    $primoNumero = $plesso?->recapiti ? trim(preg_split('/[,;\/]| - /', $plesso->recapiti)[0]) : '';
    $telefono = preg_replace('/[^\d+]/', '', $primoNumero);
    // Le sedi le modifica solo l'amministratore (area admin): il link torna poi a questa scheda.
    $correggi = $plesso && auth()->user()?->hasRole('admin')
        ? route('admin.sedi.edit', [$plesso->id_plesso, 'ritorno' => '/segnalazioni/'.$segnalazione->id_segnalazione])
        : null;
@endphp
<div class="flex flex-col gap-2 text-sm text-[var(--slate-700)]">
    @if($conMappa && $haCoordinate)
        <div id="mappa-segnalazione" class="pa-minimappa" title="Clicca per spostare la mappa"></div>
    @elseif($conMappa && $plesso)
        <div class="pa-minimappa-vuota">
            Posizione sulla mappa non trovata per {{ $plesso->indirizzo ?: $plesso->nome }}
            @if($correggi)<br><a href="{{ $correggi }}" class="font-semibold text-[var(--ente-primary)] underline">Indica sulla mappa</a>@endif
        </div>
    @endif

    @if($plesso)
        <p class="m-0 font-semibold text-[var(--ink)]">{{ $plesso->nome }}</p>
        @if($plesso->indirizzo)<p class="m-0">{{ $plesso->indirizzo }}</p>@endif
    @endif
    @if($segnalazione->ubicazione_tipo)
        <p class="m-0">{{ $segnalazione->label_ubicazione }}</p>
    @endif
    @if(! $plesso && ! $haCoordinate && ! $segnalazione->ubicazione_tipo)
        <p class="m-0 text-[var(--slate-500)]">Luogo non indicato.</p>
    @endif

    @if(! $conAzioni && $haCoordinate)
        <p class="m-0">
            <a href="{{ $naviga }}" target="_blank" rel="noopener" class="text-[var(--ente-primary)] underline">Apri in Maps</a>
            @if($correggi) · <a href="{{ $correggi }}" class="text-[var(--ente-primary)] underline">Correggi la posizione</a>@endif
        </p>
    @endif

    @if(! $conAzioni && $plesso?->recapiti)
        <div class="mt-1 flex flex-wrap items-center justify-between gap-2 border-t border-[var(--slate-100)] pt-2">
            <span class="text-xs text-[var(--slate-500)]">Telefono della sede</span>
            @if($telefono)
                <x-pa.btn href="tel:{{ $telefono }}" variant="outline" size="sm"><span aria-hidden="true"><i class="fas fa-phone"></i></span> {{ $primoNumero }}</x-pa.btn>
            @else
                <span>{{ $plesso->recapiti }}</span>
            @endif
        </div>
    @endif

    @if($conAzioni && ($naviga || $telefono))
        <div class="mt-1 flex flex-col gap-2 sm:flex-row">
            @if($naviga)
                <x-pa.btn :href="$naviga" variant="primary" size="lg" target="_blank" rel="noopener" class="w-full sm:w-auto">Naviga</x-pa.btn>
            @endif
            @if($telefono)
                <x-pa.btn href="tel:{{ $telefono }}" variant="outline" size="lg" class="w-full sm:w-auto">Chiama la sede</x-pa.btn>
            @endif
        </div>
    @endif
</div>
