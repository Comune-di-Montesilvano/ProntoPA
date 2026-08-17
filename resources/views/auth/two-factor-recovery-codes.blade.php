<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Codici di recupero 2FA') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <p class="text-sm text-gray-600">
                    Ogni codice può essere usato una sola volta per accedere se perdi l'accesso alla tua app
                    di autenticazione. Conservali in un posto sicuro: verranno rigenerati (e i vecchi invalidati)
                    ogni volta che richiedi nuovi codici.
                </p>

                <div class="mt-4 grid grid-cols-2 gap-2 font-mono text-sm bg-gray-50 rounded p-4">
                    @foreach ($codes as $code)
                        <div>{{ $code }}</div>
                    @endforeach
                </div>

                <a href="{{ route('profile.edit') }}" class="inline-block mt-6 text-sm text-gray-600 underline">
                    ← Torna al profilo
                </a>
            </div>
        </div>
    </div>
</x-app-layout>
