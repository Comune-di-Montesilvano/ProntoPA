<x-guest-layout>
    <div class="mb-5 text-sm text-gray-600">
        Registrazione per enti convenzionati. L'account resta in attesa finche non viene approvato da un operatore.
    </div>

    <form method="POST" action="{{ route('register') }}">
        @csrf

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <x-input-label for="nome" value="Nome" />
                <x-text-input id="nome" class="block mt-1 w-full" type="text" name="nome" :value="old('nome')" required autofocus autocomplete="given-name" />
                <x-input-error :messages="$errors->get('nome')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="cognome" value="Cognome" />
                <x-text-input id="cognome" class="block mt-1 w-full" type="text" name="cognome" :value="old('cognome')" required autocomplete="family-name" />
                <x-input-error :messages="$errors->get('cognome')" class="mt-2" />
            </div>
        </div>

        <div class="mt-4">
            <x-input-label for="partita_iva" value="Partita IVA ente" />
            <x-text-input id="partita_iva" class="block mt-1 w-full" type="text" name="partita_iva" :value="old('partita_iva')" required inputmode="numeric" maxlength="11" pattern="[0-9]{11}" list="partite-iva-enti" />
            <datalist id="partite-iva-enti">
                @foreach($istituti as $istituto)
                    <option value="{{ $istituto->partita_iva }}">{{ $istituto->descrizione }} ({{ $istituto->tipo_ente ?? 'ente' }})</option>
                @endforeach
            </datalist>
            <x-input-error :messages="$errors->get('partita_iva')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="email" value="Email istituzionale individuale" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />

            <x-text-input id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="new-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />

            <x-text-input id="password_confirmation" class="block mt-1 w-full"
                            type="password"
                            name="password_confirmation" required autocomplete="new-password" />

            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <a class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500" href="{{ route('login') }}">
                Torna al login
            </a>

            <x-primary-button class="ms-4">
                Invia richiesta
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
