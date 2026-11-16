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
            {{-- Selettore: scrive i due campi di sempre (visibile_web, visibile_impresa). --}}
            <fieldset class="pa-vis" x-data="{ chi: 'comune' }">
                <legend class="sr-only">Chi legge questa nota</legend>
                <label :class="chi === 'comune' && 'is-on'"><input type="radio" class="sr-only" value="comune" x-model="chi" checked><span aria-hidden="true"><i class="fas fa-lock"></i></span>Solo il comune</label>
                <label :class="chi === 'web' && 'is-on is-pub'"><input type="radio" class="sr-only" value="web" x-model="chi"><span aria-hidden="true"><i class="fas fa-eye"></i></span>La legge anche {{ $segnalazione->utente?->isSpid() ? 'la scuola' : 'chi ha segnalato' }}</label>
                @if($segnalazione->id_appalto)
                    <label :class="chi === 'ditta' && 'is-on is-pub'"><input type="radio" class="sr-only" value="ditta" x-model="chi"><span aria-hidden="true"><i class="fas fa-eye"></i></span>La legge anche la ditta</label>
                    <input type="hidden" name="visibile_impresa" :value="chi === 'ditta' ? 1 : 0" value="0">
                @endif
                <input type="hidden" name="visibile_web" :value="chi === 'web' ? 1 : 0" value="0">
            </fieldset>
        @endif

        <label class="pa-btn pa-btn-outline pa-btn-sm cursor-pointer">
            <input type="file" name="allegati[]" multiple accept="{{ $mime }}" class="sr-only"
                   x-on:change="foto = $event.target.files.length">
            <span x-text="foto ? foto + (foto === 1 ? ' file scelto' : ' file scelti') : '{{ $comune ? 'Allega' : 'Aggiungi foto' }}'">{{ $comune ? 'Allega' : 'Aggiungi foto' }}</span>
        </label>

        <x-pa.btn type="submit" variant="primary" size="sm" class="ml-auto">{{ $comune ? 'Pubblica nota' : 'Invia al comune' }}</x-pa.btn>
    </div>
    @if($comune)
        {{-- Gli allegati non hanno una visibilità propria: la scelta qui sopra vale solo per il testo. --}}
        <p class="pa-field-help m-0" :class="foto ? 'pa-testo-amber font-semibold' : ''">
            Gli allegati li vede chiunque apra la segnalazione, anche se la nota è solo per il comune.
        </p>
    @endif
</form>
