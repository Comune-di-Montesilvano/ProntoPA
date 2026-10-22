@props(['link' => true])

<div class="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800" role="alert">
    <strong>Backup non aggiornato.</strong>
    Nessun backup riuscito nelle ultime {{ config('backup.ore_allarme') }} ore.
    @if($link)
        <a href="{{ route('admin.backup.index') }}" class="font-semibold underline">Apri la pagina Backup</a>
    @endif
</div>
