{{-- Campo unico per scrivere. Il comune sceglie chi la vede; ditta e segnalante scrivono sempre al comune. --}}
@php
    $comune = $lettura === 'gestore';
    $mime = \App\Models\Impostazione::get('allegati_mime_consentiti', 'image/jpeg,image/png');
@endphp
<form id="scrivi" method="POST" action="{{ route('segnalazioni.nota', $segnalazione->id_segnalazione) }}"
      enctype="multipart/form-data" class="flex flex-col gap-3" x-data="{ foto: 0 }">
    @csrf
    <label for="testo-nota" class="sr-only">{{ $comune ? 'Scrivi una nota' : 'Scrivi al comune' }}</label>
    <textarea id="testo-nota" name="testo" rows="3" maxlength="2000" required class="pa-textarea"
              placeholder="{{ $comune ? 'Scrivi una nota…' : 'Vuoi aggiungere qualcosa?' }}">{{ old('_pannello') ? '' : old('testo') }}</textarea>
    <x-input-error :messages="$errors->get('testo')" />
    <x-input-error :messages="array_merge($errors->get('allegati'), $errors->get('allegati.*'))" />

    <div class="flex flex-wrap items-center gap-3">
        @if($comune)
            <span class="text-sm text-[var(--slate-600)]">Chi la vede: <strong>comune</strong></span>
            @if($segnalazione->id_appalto)
                <label class="inline-flex items-center gap-1.5 text-sm text-[var(--slate-700)]">
                    <input type="checkbox" name="visibile_impresa" value="1" class="rounded border-[var(--slate-300)]"> + ditta
                </label>
            @endif
            <label class="inline-flex items-center gap-1.5 text-sm text-[var(--slate-700)]">
                <input type="checkbox" name="visibile_web" value="1" class="rounded border-[var(--slate-300)]"> + segnalante
            </label>
        @endif

        <label class="pa-btn pa-btn-outline pa-btn-sm cursor-pointer">
            <input type="file" name="allegati[]" multiple accept="{{ $mime }}" class="sr-only"
                   x-on:change="foto = $event.target.files.length">
            <span x-text="foto ? foto + (foto === 1 ? ' file scelto' : ' file scelti') : '{{ $comune ? 'Allega' : 'Aggiungi foto' }}'">{{ $comune ? 'Allega' : 'Aggiungi foto' }}</span>
        </label>

        <x-pa.btn type="submit" variant="primary" size="sm" class="ml-auto">{{ $comune ? 'Pubblica nota' : 'Invia al comune' }}</x-pa.btn>
    </div>
</form>
