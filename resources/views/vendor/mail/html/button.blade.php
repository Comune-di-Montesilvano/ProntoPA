@props([
    'url',
    'color' => 'primary',
    'align' => 'center',
])
@php
    // Colore del pulsante principale = colore dell'ente (Admin → Impostazioni → brand).
    $colore = $color === 'primary' ? \App\Models\Impostazione::get('ente_colore_primario') : null;
    $stile = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $colore)
        ? "background-color: {$colore}; border-color: {$colore};"
        : '';
@endphp
<table class="action" align="{{ $align }}" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td>
<a href="{{ $url }}" class="button button-{{ $color }}" style="{{ $stile }}" target="_blank" rel="noopener">{!! $slot !!}</a>
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
</table>
