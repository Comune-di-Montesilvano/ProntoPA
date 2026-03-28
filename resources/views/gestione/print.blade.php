<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Lista Segnalazioni — {{ $tabLabels[$tab] }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10pt;
            color: #000;
            background: #fff;
            padding: 15px 20px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            border-bottom: 2px solid #000;
            padding-bottom: 6px;
            margin-bottom: 10px;
        }
        .header .titolo {
            font-size: 13pt;
            font-weight: bold;
            text-transform: uppercase;
        }
        .header .ente {
            font-size: 8pt;
            color: #b45309;
            font-weight: bold;
            text-transform: uppercase;
        }
        .header .meta {
            font-size: 8pt;
            color: #6b7280;
            text-align: right;
        }
        .filtri {
            font-size: 8.5pt;
            color: #6b7280;
            margin-bottom: 8px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8.5pt;
        }
        thead {
            background: #1f2937;
            color: #fff;
        }
        thead th {
            padding: 5px 6px;
            text-align: left;
            font-weight: bold;
        }
        tbody td {
            padding: 4px 6px;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: top;
        }
        tbody tr:nth-child(even) td { background: #f9fafb; }
        .stato {
            display: inline-block;
            font-weight: bold;
            font-size: 7.5pt;
            text-transform: uppercase;
        }
        .evidenza { color: #d97706; }
        .footer {
            margin-top: 12px;
            border-top: 1px solid #ccc;
            padding-top: 5px;
            font-size: 8pt;
            color: #6b7280;
            display: flex;
            justify-content: space-between;
        }
        @media print {
            body { padding: 5px 10px; }
            @page { margin: 1cm; size: A4 landscape; }
        }
    </style>
</head>
<body>

    <div class="header">
        <div>
            <div class="ente">{{ \App\Models\Impostazione::get('ente_nome', 'ProntoPA') }}</div>
            <div class="titolo">Lista Segnalazioni — {{ $tabLabels[$tab] }}</div>
        </div>
        <div class="meta">
            Stampato il {{ now()->format('d/m/Y H:i') }}<br>
            Tot. segnalazioni: {{ $segnalazioni->count() }}
        </div>
    </div>

    @if($q)
        <div class="filtri">Ricerca: "{{ $q }}"</div>
    @endif

    @if($segnalazioni->isEmpty())
        <p style="color:#6b7280; margin-top:10px;">Nessuna segnalazione.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th style="width:35px">#</th>
                    <th style="width:75px">Data</th>
                    <th style="width:110px">Tipologia</th>
                    <th>Descrizione</th>
                    <th style="width:110px">Plesso</th>
                    <th style="width:90px">Provenienza</th>
                    <th style="width:90px">Operatore</th>
                    <th style="width:120px">Stato</th>
                </tr>
            </thead>
            <tbody>
                @foreach($segnalazioni as $s)
                    <tr>
                        <td>
                            {{ $s->id_segnalazione }}
                            @if($s->flag_evidenza)<span class="evidenza"> ★</span>@endif
                        </td>
                        <td>{{ $s->data_segnalazione?->format('d/m/Y') }}</td>
                        <td>{{ $s->tipologia?->descrizione ?? '—' }}</td>
                        <td>{{ \Illuminate\Support\Str::limit($s->testo_segnalazione, 100) }}</td>
                        <td>{{ $s->plesso?->nome ?? '—' }}</td>
                        <td>{{ $s->provenienza?->descrizione ?? '—' }}</td>
                        <td>{{ $s->operatore?->name ?? '—' }}</td>
                        <td><span class="stato">{{ $s->stato?->descrizione ?? '—' }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">
        <div>ProntoPA — Sistema di gestione segnalazioni</div>
        <div>{{ now()->format('d/m/Y H:i') }}</div>
    </div>

    <script>window.onload = function() { window.print(); }</script>
</body>
</html>
