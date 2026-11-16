{{-- Foto da allegare: "Scatta foto" apre la fotocamera del telefono, "Scegli dalla galleria" i file.
     Le scelte si sommano nell'input vero (name="allegati[]") via DataTransfer; anteprime con "Togli".
     Gli attributi passati (es. :disabled) vanno sull'input vero. --}}
@props(['id', 'mime' => 'image/jpeg,image/png', 'name' => 'allegati[]'])
<div class="flex flex-col gap-2" x-data="{
        files: [],
        anteprime: [],
        aggiungi(lista) {
            for (const f of lista) {
                this.files.push(f);
                const i = this.anteprime.push('') - 1;
                // data: e non blob: (la CSP ammette solo data: per le immagini locali).
                if (f.type.startsWith('image/')) {
                    const r = new FileReader();
                    r.onload = () => { this.anteprime[i] = r.result; };
                    r.readAsDataURL(f);
                }
            }
            this.sincronizza();
        },
        togli(i) { this.files.splice(i, 1); this.anteprime.splice(i, 1); this.sincronizza(); },
        sincronizza() {
            try {
                const dt = new DataTransfer();
                this.files.forEach(f => dt.items.add(f));
                this.$refs.vero.files = dt.files;
            } catch (e) {}
        },
     }">
    <input id="{{ $id }}" type="file" name="{{ $name }}" multiple accept="{{ $mime }}" class="sr-only" tabindex="-1" x-ref="vero" {{ $attributes }}>
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
    <ul class="flex flex-wrap gap-2 m-0 p-0 list-none" x-show="files.length" x-cloak>
        <template x-for="(f, i) in files" :key="i + f.name">
            <li class="relative">
                <img x-show="anteprime[i]" :src="anteprime[i]" :alt="f.name" class="h-20 w-20 rounded-md object-cover border border-[var(--slate-200)]">
                <span x-show="!anteprime[i]" class="flex h-20 w-20 items-center justify-center rounded-md border border-[var(--slate-200)] text-xs text-[var(--slate-600)] break-all p-1" x-text="f.name"></span>
                <button type="button" class="absolute -top-2 -right-2 h-6 w-6 rounded-full bg-white border border-[var(--slate-300)] text-xs leading-none"
                        x-on:click="togli(i)" :aria-label="'Togli ' + f.name">&times;</button>
            </li>
        </template>
    </ul>
</div>
