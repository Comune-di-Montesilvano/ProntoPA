<x-auth-semplice titolo="Conferma la tua email" descrizione="Conferma l'indirizzo email per completare l'accesso.">
    <x-slot:sottotitolo>
        Ti abbiamo inviato un link a <strong>{{ auth()->user()->email }}</strong>: aprilo per confermare
        l'indirizzo. Se non lo trovi, controlla lo spam o fattelo rimandare.
    </x-slot:sottotitolo>

    @if (session('status') == 'verification-link-sent')
        <div role="status" style="background:var(--emerald-100); color:var(--emerald); border-radius:var(--radius-sm); padding:10px 14px; font-size:13px;">
            Ti abbiamo inviato un nuovo link di conferma.
        </div>
    @endif

    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <button type="submit" class="pa-btn pa-btn-primary pa-btn-lg" style="width:100%;">Invia di nuovo il link</button>
    </form>

    <p style="font-size:13px; color:var(--slate-600); margin:0;">
        Email sbagliata? <a href="{{ route('profile.edit') }}" style="color:var(--ente-primary); font-weight:600;">Modificala nel profilo</a>.
    </p>

    <form method="POST" action="{{ route('logout') }}" style="text-align:center;">
        @csrf
        <button type="submit" style="background:none; border:none; cursor:pointer; font-size:13px; color:var(--slate-600); text-decoration:underline;">Esci</button>
    </form>
</x-auth-semplice>
