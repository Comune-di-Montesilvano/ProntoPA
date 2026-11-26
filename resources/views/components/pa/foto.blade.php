{{-- Foto da allegare: "Scatta foto" apre la fotocamera del telefono, "Scegli dalla galleria" i file.
     Ogni foto è ridotta nel browser e caricata subito a blocchi (resources/js/foto.js, AllegatiTemporanei):
     il form invia solo gli id in `allegati_caricati[]`, il middleware RicomponiAllegatiCaricati li rimette in `allegati[]`.
     Gli attributi passati (es. :disabled) vanno sull'input nascosto: disabilitato = foto non inviate. --}}
@props(['id', 'mime' => 'image/jpeg,image/png'])
@php
    $configurazione = [
        'url' => route('allegati.temporanei.store'),
        'campo' => \App\Http\Middleware\RicomponiAllegatiCaricati::CAMPO.'[]',
        'mime' => array_values(array_filter(array_map('trim', explode(',', $mime)))),
        'massimo' => max(1, (int) \App\Models\Impostazione::get('allegati_max_per_request', 5)),
    ];
@endphp
<div class="flex flex-col gap-2" x-data="paFoto(@js($configurazione))">
    <input id="{{ $id }}" type="file" class="sr-only" tabindex="-1" x-ref="vero" {{ $attributes }}>
    <div class="flex flex-wrap gap-2">
        <label class="pa-btn pa-btn-outline pa-btn-sm cursor-pointer">
            <input type="file" accept="image/*" capture="environment" class="sr-only"
                   x-on:change="aggiungi($event.target.files); $event.target.value = ''">
            <span aria-hidden="true"><i class="fas fa-camera"></i></span> Scatta foto
        </label>
        <label class="pa-btn pa-btn-outline pa-btn-sm cursor-pointer">
            <input type="file" accept="{{ $mime }}" multiple class="sr-only"
                   x-on:change="aggiungi($event.target.files); $event.target.value = ''">
            <span aria-hidden="true"><i class="fas fa-images"></i></span> Scegli dalla galleria
        </label>
    </div>
    <ul class="flex flex-wrap gap-2 m-0 p-0 list-none" x-show="foto.length" x-cloak>
        <template x-for="(f, i) in foto" :key="f.chiave">
            <li class="relative">
                <img x-show="f.anteprima" :src="f.anteprima" :alt="f.nome" class="h-20 w-20 rounded-md object-cover border border-[var(--slate-200)]"
                     :class="f.stato !== 'ok' && 'opacity-50'">
                <span x-show="!f.anteprima" class="flex h-20 w-20 items-center justify-center rounded-md border border-[var(--slate-200)] text-xs text-[var(--slate-600)] break-all p-1" x-text="f.nome"></span>
                <span x-show="f.stato === 'caricamento'" class="absolute inset-x-0 bottom-0 rounded-b-md bg-white/90 text-center text-xs font-semibold text-[var(--slate-700)]"
                      x-text="f.percentuale + '%'"></span>
                <button type="button" x-show="f.stato === 'errore'" x-on:click="riprova(f)" :title="f.errore"
                        class="absolute inset-x-0 bottom-0 rounded-b-md bg-red-600 text-center text-xs font-semibold text-white">Riprova</button>
                <button type="button" class="absolute -top-2 -right-2 h-6 w-6 rounded-full bg-white border border-[var(--slate-300)] text-xs leading-none"
                        x-on:click="togli(i)" :aria-label="'Togli ' + f.nome">&times;</button>
            </li>
        </template>
    </ul>
    <template x-for="f in foto.filter((f) => f.stato === 'errore')" :key="'e' + f.chiave">
        <p class="m-0 text-sm text-red-700" role="alert" x-text="f.nome + ': ' + f.errore"></p>
    </template>
    <p x-show="avviso" x-text="avviso" class="m-0 text-sm font-semibold text-[var(--slate-700)]" role="status" x-cloak></p>
</div>
