{{-- Partial condiviso da create e edit --}}
<div>
    <x-input-label for="ragione_sociale" value="Ragione sociale *" />
    <x-text-input id="ragione_sociale" name="ragione_sociale" type="text"
        class="mt-1 block w-full text-sm"
        :value="old('ragione_sociale', $impresa->ragione_sociale ?? '')"
        required />
    <x-input-error :messages="$errors->get('ragione_sociale')" class="mt-1" />
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
        <x-input-label for="partita_iva" value="Partita IVA" />
        <x-text-input id="partita_iva" name="partita_iva" type="text"
            class="mt-1 block w-full text-sm font-mono"
            :value="old('partita_iva', $impresa->partita_iva ?? '')" />
        <x-input-error :messages="$errors->get('partita_iva')" class="mt-1" />
    </div>
    <div>
        <x-input-label for="referente" value="Referente" />
        <x-text-input id="referente" name="referente" type="text"
            class="mt-1 block w-full text-sm"
            :value="old('referente', $impresa->referente ?? '')" />
        <x-input-error :messages="$errors->get('referente')" class="mt-1" />
    </div>
    <div>
        <x-input-label for="email" value="Email" />
        <x-text-input id="email" name="email" type="email"
            class="mt-1 block w-full text-sm"
            :value="old('email', $impresa->email ?? '')" />
        <x-input-error :messages="$errors->get('email')" class="mt-1" />
    </div>
    <div>
        <x-input-label for="cellulare" value="Cellulare" />
        <x-text-input id="cellulare" name="cellulare" type="text"
            class="mt-1 block w-full text-sm"
            :value="old('cellulare', $impresa->cellulare ?? '')" />
        <x-input-error :messages="$errors->get('cellulare')" class="mt-1" />
    </div>
</div>

<div>
    <x-input-label for="note" value="Note" />
    <textarea id="note" name="note" rows="3"
              class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">{{ old('note', $impresa->note ?? '') }}</textarea>
    <x-input-error :messages="$errors->get('note')" class="mt-1" />
</div>
