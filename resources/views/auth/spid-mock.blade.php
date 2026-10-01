<x-auth-semplice titolo="Simulatore SPID">
    <x-slot:sottotitolo>Solo sviluppo (OIDC_MOCK=true): nessuna verifica d'identità.</x-slot:sottotitolo>

    <form method="POST" action="{{ route('spid.mock.store') }}" style="display:flex; flex-direction:column; gap:16px;">
        @csrf
        <div class="pa-field">
            <label class="pa-field-label" for="codice_fiscale">Codice fiscale</label>
            <input id="codice_fiscale" name="codice_fiscale" class="pa-input" required value="{{ old('codice_fiscale', 'RSSMRA80A01G482X') }}">
        </div>
        <div class="pa-field">
            <label class="pa-field-label" for="nome">Nome</label>
            <input id="nome" name="nome" class="pa-input" required value="{{ old('nome', 'Mario') }}">
        </div>
        <div class="pa-field">
            <label class="pa-field-label" for="cognome">Cognome</label>
            <input id="cognome" name="cognome" class="pa-input" required value="{{ old('cognome', 'Rossi') }}">
        </div>
        <div class="pa-field">
            <label class="pa-field-label" for="email">Email (claim SPID, facoltativa)</label>
            <input id="email" name="email" type="email" class="pa-input" value="{{ old('email') }}">
        </div>
        <button type="submit" class="pa-btn pa-btn-primary pa-btn-lg" style="width:100%;">Entra (simulato)</button>
    </form>
</x-auth-semplice>
