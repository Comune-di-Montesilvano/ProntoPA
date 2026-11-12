@props(['chiave', 'tipo' => 'testo', 'etichetta', 'aiuto' => null, 'valore' => null])

@php
    use App\Models\Impostazione;
    use App\Models\StatoSegnalazione;

    $nome = "impostazioni[{$chiave}]";
    $errore = $errors->first("impostazioni.{$chiave}");
    $id = 'imp-' . $chiave;
    $segreto = in_array($tipo, ['segreto', 'segreto_cifrato'], true);
    $campo = 'block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm';

    // Valore corrente: mai letto per i segreti (solo "configurato sì/no").
    $corrente = $valore ?? Impostazione::get($chiave);
    $configurato = $segreto && filled($corrente);
    if (! $segreto) {
        $corrente = old("impostazioni.{$chiave}", $corrente);
    }

    $opzioni = match ($tipo) {
        'stato' => StatoSegnalazione::orderBy('id_stato')->pluck('descrizione', 'id_stato')->all(),
        'disco' => ['local' => 'Sul server', 's3' => 'S3 o compatibile'],
        default => [],
    };

    $tipoInput = match ($tipo) {
        'url' => 'url',
        'email' => 'email',
        'intero' => 'number',
        'decimale' => 'text',
        'colore' => 'color',
        'ora' => 'time',
        default => 'text',
    };
@endphp

<div {{ $attributes->class(['flex flex-col gap-1']) }}>
    @if($tipo === 'bool')
        @php($attivo = old('impostazioni') !== null ? old("impostazioni.{$chiave}") : $corrente)
        <input type="hidden" name="{{ $nome }}" value="0">
        <label for="{{ $id }}" class="inline-flex items-center gap-2 text-sm font-medium text-gray-800">
            <input type="checkbox" id="{{ $id }}" name="{{ $nome }}" value="1"
                   @checked($attivo === '1' || $attivo === 1 || $attivo === true)
                   class="rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500">
            {{ $etichetta }}
        </label>
    @else
        <label for="{{ $id }}" class="text-sm font-medium text-gray-800">{{ $etichetta }}</label>

        @if($opzioni || in_array($tipo, ['stato', 'disco'], true))
            <select id="{{ $id }}" name="{{ $nome }}" class="{{ $campo }}">
                @if($tipo === 'stato')
                    <option value="">Nessuno</option>
                @endif
                @foreach($opzioni as $v => $testo)
                    <option value="{{ $v }}" @selected((string) $corrente === (string) $v)>{{ $testo }}</option>
                @endforeach
            </select>
        @elseif($segreto)
            <input type="password" id="{{ $id }}" name="{{ $nome }}" value="" autocomplete="new-password"
                   placeholder="{{ $configurato ? 'Configurato — lascia vuoto per non modificarlo' : 'Non configurato' }}"
                   class="{{ $campo }}">
        @elseif($tipo === 'colore')
            <input type="color" id="{{ $id }}" name="{{ $nome }}" value="{{ $corrente ?: ($chiave === 'ente_colore_secondario' ? '#1E40AF' : '#1D4ED8') }}"
                   class="h-10 w-20 rounded-md border-gray-300 shadow-sm">
        @else
            <input type="{{ $tipoInput }}" id="{{ $id }}" name="{{ $nome }}" value="{{ $corrente }}"
                   @if($tipo === 'intero') min="0" step="1" @endif
                   @if($tipo === 'decimale') inputmode="decimal" @endif
                   @if($tipo === 'orario') placeholder="08:00-14:00 (vuoto = chiuso)" @endif
                   @if($tipo === 'patrono') placeholder="GG/MM, es. 13/06" @endif
                   class="{{ $campo }}">
        @endif
    @endif

    @if($aiuto)
        <p class="text-xs text-gray-500">{{ $aiuto }}</p>
    @endif
    @if($errore)
        <p class="text-xs text-red-600">{{ $errore }}</p>
    @endif
</div>
