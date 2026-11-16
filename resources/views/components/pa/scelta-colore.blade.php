{{-- Tendina dei toni della tavolozza unica (App\Support\Colori), con anteprima del colore scelto. --}}
@props(['id', 'valore' => null, 'name' => 'colore'])
<span class="inline-flex items-center gap-2" x-data="{ c: @js($valore ?: '') }">
    <span class="inline-block h-6 w-6 rounded-md border border-[var(--slate-200)]" :class="'pa-tono-' + (c || 'grigio')"
          style="background: var(--tono-sfondo); box-shadow: inset 0 0 0 3px var(--tono-punto)" aria-hidden="true"></span>
    <select id="{{ $id }}" name="{{ $name }}" class="pa-input pa-input-auto" x-model="c">
        <option value="">Grigio (nessuno)</option>
        @foreach(\App\Support\Colori::NOMI as $tono => $nome)
            @continue($tono === 'grigio')
            <option value="{{ $tono }}">{{ $nome }}</option>
        @endforeach
    </select>
</span>
