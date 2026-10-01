<x-auth-semplice titolo="Completa il profilo" descrizione="Primo accesso con SPID o CIE: indica l'email su cui ricevere gli aggiornamenti.">
    <x-slot:sottotitolo>
        Ciao {{ $identita->nomeCompleto() }}. Indica l'email su cui ricevere gli aggiornamenti delle segnalazioni:
        ti invieremo un link per confermarla.
    </x-slot:sottotitolo>

    <form method="POST" action="{{ route('spid.completa-profilo.store') }}" style="display:flex; flex-direction:column; gap:20px;">
        @csrf
        <div class="pa-field">
            <label class="pa-field-label" for="email">Email</label>
            <input id="email" type="email" name="email" class="pa-input" required autofocus autocomplete="email"
                   value="{{ old('email', $identita->email) }}">
        </div>
        <button type="submit" class="pa-btn pa-btn-primary pa-btn-lg" style="width:100%;">Continua</button>
    </form>
</x-auth-semplice>
