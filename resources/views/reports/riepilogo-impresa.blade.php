<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riepilogo lavori{{ $impresa ? ' — '.$impresa->ragione_sociale : '' }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; font-size: 12px; color: #1f2937; padding: 20px; }
        h1 { font-size: 18px; font-weight: bold; margin-bottom: 4px; }
        .subtitle { color: #6b7280; font-size: 11px; margin-bottom: 20px; }
        .kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 20px; }
        .kpi-box { border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px; text-align: center; }
        .kpi-value { font-size: 18px; font-weight: bold; }
        .kpi-label { font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: .03em; margin-top: 2px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #f3f4f6; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; padding: 6px 8px; text-align: left; border-bottom: 2px solid #d1d5db; }
        td { padding: 6px 8px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
        tr:last-child td { border-bottom: none; }
        .footer { margin-top: 20px; font-size: 10px; color: #9ca3af; text-align: right; }
        .text-right { text-align: right; }
        @media print {
            body { padding: 10px; }
            .no-print { display: none; }
            @page { margin: 1.5cm; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom:16px">
        <button onclick="window.print()" style="padding:6px 16px;background:#2563eb;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:12px;">Stampa / Salva PDF</button>
        <a href="{{ route('imprese.reports.riepilogo.xlsx', array_filter(['id_impresa' => request('id_impresa'), 'data_da' => $dataDa, 'data_a' => $dataA])) }}"
           class="no-print" style="padding:6px 16px;background:#16a34a;color:#fff;border-radius:4px;font-size:12px;text-decoration:none;display:inline-block;margin-left:8px;">
            Scarica XLSX
        </a>
        <a href="{{ route('imprese.dashboard') }}" style="margin-left:10px;font-size:12px;color:#6b7280;">← Torna alla dashboard</a>
    </div>

    <h1>Riepilogo lavori@if($impresa) — {{ $impresa->ragione_sociale }}@endif</h1>
    <p class="subtitle">Dal {{ \Carbon\Carbon::parse($dataDa)->format('d/m/Y') }} al {{ \Carbon\Carbon::parse($dataA)->format('d/m/Y') }} &nbsp;·&nbsp; Generato il {{ now()->format('d/m/Y H:i') }}</p>

    <div class="kpi-grid">
        <div class="kpi-box"><div class="kpi-value">{{ $segnalazioni->count() }}</div><div class="kpi-label">Interventi</div></div>
        <div class="kpi-box"><div class="kpi-value" style="color:#2563eb">{{ $segnalazioni->filter(fn($s) => !$s->isChiusa())->count() }}</div><div class="kpi-label">Aperti</div></div>
        <div class="kpi-box"><div class="kpi-value" style="color:#7c3aed">€ {{ number_format($totaleAffidato, 0, ',', '.') }}</div><div class="kpi-label">Importo affidato</div></div>
        <div class="kpi-box"><div class="kpi-value" style="color:#16a34a">€ {{ number_format($totaleLiquidato, 0, ',', '.') }}</div><div class="kpi-label">Importo liquidato</div></div>
    </div>

    @if($segnalazioni->isEmpty())
        <p style="text-align:center;color:#9ca3af;padding:30px 0;">Nessun intervento nel periodo.</p>
    @else
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Data</th>
                <th>Tipologia</th>
                <th>Plesso</th>
                <th>Appalto</th>
                <th>Stato</th>
                <th class="text-right">Imp. preventivo</th>
                <th class="text-right">Imp. liquidato</th>
                <th>Chiusura</th>
            </tr>
        </thead>
        <tbody>
            @foreach($segnalazioni as $s)
            <tr>
                <td style="font-family:monospace;color:#6b7280">{{ $s->id_segnalazione }}</td>
                <td>{{ $s->data_segnalazione?->format('d/m/Y') }}</td>
                <td>{{ $s->tipologia?->descrizione ?? '—' }}</td>
                <td>{{ $s->plesso?->nome ?? '—' }}</td>
                <td>{{ $s->appalto?->descrizione ?? '—' }}</td>
                <td>{{ $s->stato?->descrizione ?? '—' }}</td>
                <td class="text-right">{{ $s->importo_preventivo ? '€ '.number_format($s->importo_preventivo, 2, ',', '.') : '—' }}</td>
                <td class="text-right">{{ $s->importo_liquidato ? '€ '.number_format($s->importo_liquidato, 2, ',', '.') : '—' }}</td>
                <td>{{ $s->data_chiusura?->format('d/m/Y') ?? '—' }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr style="font-weight:bold;background:#f9fafb;">
                <td colspan="6">Totale</td>
                <td class="text-right">—</td>
                <td class="text-right">€ {{ number_format($totaleLiquidato, 2, ',', '.') }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    @endif

    <div class="footer">ProntoPA &mdash; report generato automaticamente</div>
</body>
</html>
