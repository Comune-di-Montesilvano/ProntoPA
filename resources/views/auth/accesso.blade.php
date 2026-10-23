<x-auth-semplice titolo="Accesso scuole e ditte">
    <x-slot:sottotitolo>Entra con la tua identità digitale. Per le ditte serve la delega dell'ente: se non ce l'hai ancora, contatta l'ufficio che gestisce l'appalto.</x-slot:sottotitolo>

    @if(app(\App\Services\Oidc\OidcConfig::class)->configurato() || config('oidc.mock'))
        <a href="{{ route('spid.start') }}" class="pa-btn pa-btn-primary pa-btn-lg" style="width:100%; justify-content:center;">
            Entra con SPID o CIE
        </a>
    @else
        <div role="status" style="background:var(--amber-100); color:var(--amber-600); border-radius:var(--radius-sm); padding:10px 14px; font-size:13px;">
            L'accesso con SPID/CIE non è ancora configurato. Contatta l'ente.
        </div>
    @endif

    <p style="font-size:12px; color:var(--slate-500); text-align:center; margin:0;">
        Personale del comune?
        <a href="{{ route('login') }}" style="color:var(--ente-primary); font-weight:600; text-decoration:none;">
            Accedi con le credenziali di dominio
        </a>
    </p>
</x-auth-semplice>
