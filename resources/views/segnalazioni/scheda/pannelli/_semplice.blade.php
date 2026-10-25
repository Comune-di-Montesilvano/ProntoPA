{{-- Pannello per un'azione del workflow che chiede al più una nota. Parametri: nome, titolo, azione, etichettaNota, notaObbligatoria, bottone, intro. --}}
@php $mio = old('_pannello') === $nome; @endphp
<x-pa.drawer :name="$nome" :title="$titolo" :sottotitolo="'#'.$segnalazione->id_segnalazione" :aperto="$mio">
    <form method="POST" action="{{ route('segnalazioni.azione', $segnalazione->id_segnalazione) }}" class="flex flex-1 flex-col">
        @csrf
        <input type="hidden" name="_pannello" value="{{ $nome }}">
        <input type="hidden" name="id_azione" value="{{ $azione->id_azione }}">
        <div class="flex flex-1 flex-col gap-4 px-6 py-5">
            @if(! empty($intro))<p class="m-0 text-sm text-[var(--slate-700)]">{{ $intro }}</p>@endif
            <div class="pa-field">
                <label for="nota-{{ $nome }}" class="pa-field-label">{{ $etichettaNota }}</label>
                <textarea id="nota-{{ $nome }}" name="nota" rows="4" maxlength="2000" class="pa-textarea" @required($notaObbligatoria)>{{ $mio ? old('nota') : '' }}</textarea>
                <x-input-error :messages="$errors->get('nota')" />
            </div>
            @if($mio)<x-input-error :messages="$errors->get('azione')" />@endif
        </div>
        <footer class="flex justify-end gap-2 border-t border-[var(--slate-200)] px-6 py-4">
            <x-pa.btn x-on:click="$dispatch('apri-pannello', '')">Annulla</x-pa.btn>
            <x-pa.btn type="submit" :variant="($pericolosa ?? false) ? 'danger' : 'primary'">{{ $bottone }}</x-pa.btn>
        </footer>
    </form>
</x-pa.drawer>
