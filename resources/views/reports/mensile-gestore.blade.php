<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report mensile — {{ sprintf('%02d/%d', $mese, $anno) }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; font-size: 12px; color: #1f2937; padding: 20px; }
        h1 { font-size: 18px; font-weight: bold; margin-bottom: 4px; }
        .subtitle { color: #6b7280; font-size: 11px; margin-bottom: 20px; }
        .kpi-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; margin-bottom: 20px; }
        .kpi-box { border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px; text-align: center; }
        .kpi-value { font-size: 22px; font-weight: bold; }
        .kpi-label { font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: .03em; margin-top: 2px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #f3f4f6; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; padding: 6px 8px; text-align: left; border-bottom: 2px solid #d1d5db; }
        td { padding: 6px 8px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
        tr:last-child td { border-bottom: none; }
        .badge-critica { color: #b91c1c; font-weight: bold; }
        .badge-alta { color: #d97706; }
        .badge-media { color: #2563eb; }
        .badge-bassa { color: #6b7280; }
        .tag-urgente { background: #fee2e2; color: #b91c1c; padding: 1px 5px; border-radius: 4px; font-size: 10px; font-weight: bold; }
        .footer { margin-top: 20px; font-size: 10px; color: #9ca3af; text-align: right; }
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
        <a href="{{ route('gestione.reports.mensile.xlsx', ['mese' => $mese, 'anno' => $anno]) }}"
           style="margin-left:8px;padding:6px 16px;background:#16a34a;color:#fff;border-radius:4px;font-size:12px;text-decoration:none;display:inline-block;">
            Scarica XLSX
        </a>
        <a href="{{ route('gestione.dashboard') }}" style="margin-left:10px;font-size:12px;color:#6b7280;">← Torna alla dashboard</a>
    </div>

    <h1>Report mensile interventi</h1>
    <p class="subtitle">{{ ucfirst($dal->isoFormat('MMMM YYYY')) }} &nbsp;·&nbsp; Generato il {{ now()->format('d/m/Y H:i') }}</p>

    <div class="kpi-grid">
        <div class="kpi-box"><div class="kpi-value">{{ $kpi['totale'] }}</div><div class="kpi-label">Totale</div></div>
        <div class="kpi-box"><div class="kpi-value" style="color:#2563eb">{{ $kpi['aperte'] }}</div><div class="kpi-label">Aperte</div></div>
        <div class="kpi-box"><div class="kpi-value" style="color:#16a34a">{{ $kpi['chiuse'] }}</div><div class="kpi-label">Chiuse</div></div>
        <div class="kpi-box"><div class="kpi-value" style="color:#b91c1c">{{ $kpi['sla_violato'] }}</div><div class="kpi-label">SLA violato</div></div>
        <div class="kpi-box"><div class="kpi-value" style="color:#d97706">{{ $kpi['urgenti'] }}</div><div class="kpi-label">Urgenti</div></div>
    </div>

    @if($segnalazioni->isEmpty())
        <p style="text-align:center;color:#9ca3af;padding:30px 0;">Nessuna segnalazione nel periodo.</p>
    @else
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Data</th>
                <th>Tipologia</th>
                <th>Plesso</th>
                <th>Operatore</th>
                <th>Impresa</th>
                <th>Stato</th>
                <th>Priorità</th>
            </tr>
        </thead>
        <tbody>
            @foreach($segnalazioni as $s)
            <tr>
                <td style="font-family:monospace;color:#6b7280">{{ $s->id_segnalazione }}</td>
                <td>{{ $s->data_segnalazione?->format('d/m/Y') }}</td>
                <td>{{ $s->tipologia?->descrizione ?? '—' }}</td>
                <td>{{ $s->plesso?->nome ?? '—' }}</td>
                <td>{{ $s->operatore?->name ?? '—' }}</td>
                <td>{{ $s->appalto?->impresa?->ragione_sociale ?? '—' }}</td>
                <td>{{ $s->stato?->descrizione ?? '—' }}</td>
                <td>
                    <span class="badge-{{ strtolower($s->label_priorita) }}">{{ $s->label_priorita }}</span>
                    @if($s->segnalazione_urgente) <span class="tag-urgente">U</span> @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <div class="footer">ProntoPA &mdash; report generato automaticamente</div>
</body>
</html>
