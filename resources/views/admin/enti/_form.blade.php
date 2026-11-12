{{-- Campi dell'ente, condivisi da "nuovo" e scheda. Richiede $ente (anche non salvato) e $categorie. --}}
@php $miur = $ente->exists && $ente->isMiur(); @endphp
@if($miur)
    <p class="text-sm bg-blue-50 text-blue-800 rounded-md p-3">
        Dati da anagrafe MIUR: nome, codice ed email si aggiornano da
        <a href="{{ route('admin.anagrafe-miur.show', $ente->codice_meccanografico) }}" class="underline">Aggiungi da MIUR</a>.
    </p>
@endif

<div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
    <div>
        <x-input-label for="descrizione" value="Nome *" />
        <x-text-input id="descrizione" name="descrizione" :readonly="$miur" type="text"
                      class="mt-1 block w-full"
                      :value="old('descrizione', $ente->descrizione)" required maxlength="255" />
        <x-input-error :messages="$errors->get('descrizione')" class="mt-1" />
    </div>

    <div>
        <x-input-label for="id_categoria" value="Categoria *" />
        <select id="id_categoria" name="id_categoria" required
                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 @error('id_categoria') border-red-500 @enderror">
            @foreach($categorie as $c)
                <option value="{{ $c->id_categoria }}" {{ (int) old('id_categoria', $ente->id_categoria) === $c->id_categoria ? 'selected' : '' }}>{{ $c->nome }}</option>
            @endforeach
        </select>
        <x-input-error :messages="$errors->get('id_categoria')" class="mt-1" />
    </div>
</div>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
    <div>
        <x-input-label for="partita_iva" value="Partita IVA" />
        <x-text-input id="partita_iva" name="partita_iva" type="text" class="mt-1 block w-full"
                      :value="old('partita_iva', $ente->partita_iva)" maxlength="11" />
        <x-input-error :messages="$errors->get('partita_iva')" class="mt-1" />
    </div>
    <div>
        <x-input-label for="codice_fiscale" value="Codice fiscale" />
        <x-text-input id="codice_fiscale" name="codice_fiscale" type="text" class="mt-1 block w-full"
                      :value="old('codice_fiscale', $ente->codice_fiscale)" maxlength="16" />
        <x-input-error :messages="$errors->get('codice_fiscale')" class="mt-1" />
    </div>
    <div>
        <x-input-label for="codice_meccanografico" value="Codice meccanografico" />
        <x-text-input id="codice_meccanografico" name="codice_meccanografico" :readonly="$miur" type="text"
                      class="mt-1 block w-full"
                      :value="old('codice_meccanografico', $ente->codice_meccanografico)" maxlength="50" />
        <x-input-error :messages="$errors->get('codice_meccanografico')" class="mt-1" />
    </div>
</div>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
    <div>
        <x-input-label for="dirigente" value="Dirigente / Referente" />
        <x-text-input id="dirigente" name="dirigente" type="text" class="mt-1 block w-full"
                      :value="old('dirigente', $ente->dirigente)" maxlength="50" />
        <x-input-error :messages="$errors->get('dirigente')" class="mt-1" />
    </div>
    <div>
        <x-input-label for="email" value="Email" />
        <x-text-input id="email" name="email" :readonly="$miur" type="email" class="mt-1 block w-full"
                      :value="old('email', $ente->email)" maxlength="50" />
        <x-input-error :messages="$errors->get('email')" class="mt-1" />
    </div>
    <div>
        <x-input-label for="recapiti" value="Recapiti telefonici" />
        <x-text-input id="recapiti" name="recapiti" type="text" class="mt-1 block w-full"
                      :value="old('recapiti', $ente->recapiti)" maxlength="50" />
        <x-input-error :messages="$errors->get('recapiti')" class="mt-1" />
    </div>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
    <div>
        <x-input-label for="domini_email_istituzionali" value="Domini email istituzionali" />
        <x-text-input id="domini_email_istituzionali" name="domini_email_istituzionali" type="text"
                      class="mt-1 block w-full"
                      :value="old('domini_email_istituzionali', $ente->domini_email_istituzionali)"
                      placeholder="es. comune.it scuola.edu.it" maxlength="255" />
        <x-input-error :messages="$errors->get('domini_email_istituzionali')" class="mt-1" />
        <p class="mt-1 text-xs text-gray-500">Separare i domini con spazio, virgola o punto e virgola.</p>
    </div>
    <div class="space-y-3">
        <label for="attivo" class="flex items-start gap-2">
            <input id="attivo" name="attivo" type="checkbox" value="1"
                   class="mt-0.5 rounded border-gray-300 text-blue-600 shadow-sm"
                   {{ old('attivo', $ente->attivo) ? 'checked' : '' }}>
            <span class="text-sm text-gray-700">Attivo
                <span class="block text-xs text-gray-500">Usabile per sedi, segnalazioni e deleghe assegnate dall'amministratore.</span></span>
        </label>
        <label for="richieste_delega_spid" class="flex items-start gap-2">
            <input id="richieste_delega_spid" name="richieste_delega_spid" type="checkbox" value="1"
                   class="mt-0.5 rounded border-gray-300 text-blue-600 shadow-sm"
                   {{ old('richieste_delega_spid', $ente->richieste_delega_spid) ? 'checked' : '' }}>
            <span class="text-sm text-gray-700">Accetta richieste di delega via SPID/CIE
                <span class="block text-xs text-gray-500">Le persone esterne lo trovano e chiedono una delega, approvata dalla segreteria via email. Spento: deleghe solo dall'amministratore (Deleghe → predelega).</span></span>
        </label>
    </div>
</div>
