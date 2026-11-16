{{-- Il comune chiede informazioni: riquadro ambra in cima alla scheda di chi ha segnalato, con la risposta. --}}
@php $mime = \App\Models\Impostazione::get('allegati_mime_consentiti', 'image/jpeg,image/png'); @endphp
<section class="pa-card pa-passo pa-passo-amber p-5 flex flex-col gap-3" aria-labelledby="richiesta-titolo">
    <p id="richiesta-titolo" class="pa-sezione-titolo pa-testo-amber m-0">L'ufficio ti chiede</p>
    <p class="m-0 text-lg font-semibold text-[var(--ink)] whitespace-pre-wrap break-words">«{{ $richiesta->testo }}»</p>
    <p class="m-0 text-[var(--slate-700)]">
        Rispondi entro {{ $richiesta->termine_at->locale('it')->isoFormat('dddd D MMMM') }}@if($richiesta->chiudi_senza_risposta): senza risposta la segnalazione verrà chiusa.@else.@endif
    </p>
    <form method="POST" action="{{ route('segnalazioni.aggiornamento', $segnalazione) }}" enctype="multipart/form-data"
          class="flex flex-col gap-3" x-data="{ foto: 0 }">
        @csrf
        <label for="risposta-testo" class="sr-only">La tua risposta</label>
        <textarea id="risposta-testo" name="testo" rows="3" maxlength="2000" class="pa-textarea" placeholder="La tua risposta"></textarea>
        <x-input-error :messages="$errors->get('testo')" />
        <div class="flex flex-wrap items-center gap-3">
            <label class="pa-btn pa-btn-outline pa-btn-sm cursor-pointer">
                <input type="file" name="allegati[]" multiple accept="{{ $mime }}" class="sr-only" x-on:change="foto = $event.target.files.length">
                <span x-text="foto ? foto + (foto === 1 ? ' foto scelta' : ' foto scelte') : 'Aggiungi foto'">Aggiungi foto</span>
            </label>
            <x-pa.btn type="submit" variant="primary" class="ml-auto">Rispondi</x-pa.btn>
        </div>
    </form>
</section>
