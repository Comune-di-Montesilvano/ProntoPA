<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scheda Segnalazione #{{ $segnalazione->id_segnalazione }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11pt;
            color: #000;
            background: #fff;
            padding: 20px 30px;
        }

        /* ── Header ── */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #000;
            padding-bottom: 8px;
            margin-bottom: 14px;
        }
        .header-left .ente {
            font-size: 8pt;
            font-weight: bold;
            text-transform: uppercase;
            color: #b45309;
            letter-spacing: 0.05em;
        }
        .header-left .titolo {
            font-size: 14pt;
            font-weight: bold;
            text-transform: uppercase;
        }
        .header-right .stato-badge {
            display: inline-block;
            border: 2px solid #92400e;
            color: #92400e;
            font-weight: bold;
            font-size: 9pt;
            text-transform: uppercase;
            padding: 4px 10px;
            letter-spacing: 0.05em;
        }

        /* ── Tipologia ── */
        .tipologia {
            font-size: 13pt;
            font-weight: bold;
            text-transform: uppercase;
            border-bottom: 1px solid #ccc;
            padding: 8px 0;
            margin-bottom: 10px;
        }
        .tipologia span { font-weight: normal; }

        /* ── Metadati riga ── */
        .meta-row {
            display: flex;
            gap: 30px;
            margin-bottom: 8px;
            font-size: 10pt;
        }
        .meta-row .label { font-weight: bold; }

        /* ── Plesso ── */
        .plesso-block {
            margin-bottom: 8px;
            font-size: 10pt;
        }
        .plesso-block .plesso-nome {
            font-weight: bold;
            text-transform: uppercase;
        }
        .plesso-block .plesso-indirizzo {
            color: #1d4ed8;
            font-size: 9pt;
            margin-top: 2px;
        }
        .plesso-block .plesso-referente {
            color: #1d4ed8;
            font-size: 9pt;
        }
        .plesso-block .dirigente {
            font-size: 10pt;
            margin-top: 3px;
        }

        /* ── Testo segnalazione ── */
        .testo-box {
            border: 1px solid #000;
            padding: 8px 10px;
            margin: 10px 0;
        }
        .testo-box .testo-label {
            font-weight: bold;
            font-size: 10pt;
            text-transform: uppercase;
            margin-bottom: 6px;
        }
        .testo-box .testo-content {
            font-style: italic;
            font-size: 10pt;
            line-height: 1.5;
        }

        /* ── Registratore ── */
        .registratore {
            font-size: 10pt;
            margin-bottom: 12px;
        }

        /* ── Storico ── */
        .section-title {
            font-size: 11pt;
            font-weight: bold;
            text-transform: uppercase;
            border-bottom: 1px solid #000;
            padding-bottom: 3px;
            margin: 12px 0 6px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5pt;
        }
        table thead {
            background: #1f2937;
            color: #fff;
        }
        table thead th {
            padding: 5px 8px;
            text-align: left;
            font-weight: bold;
        }
        table tbody td {
            padding: 4px 8px;
            border-bottom: 1px solid #e5e7eb;
        }
        table tbody tr:nth-child(even) td { background: #f9fafb; }

        /* ── Note ── */
        .nota {
            border-bottom: 1px solid #e5e7eb;
            padding: 5px 0;
            font-size: 10pt;
        }
        .nota .nota-meta {
            font-size: 8.5pt;
            color: #6b7280;
            margin-bottom: 2px;
        }

        /* ── Footer ── */
        .footer {
            margin-top: 20px;
            border-top: 1px solid #ccc;
            padding-top: 6px;
            font-size: 8pt;
            color: #6b7280;
            display: flex;
            justify-content: space-between;
        }

        @media print {
            body { padding: 10px 15px; }
            @page { margin: 1cm; size: A4; }
        }
    </style>
</head>
<body>

    {{-- Header --}}
    <div class="header">
        <div class="header-left">
            <div class="ente">
                {{ $segnalazione->tipologia?->gruppo?->descrizione ?? \App\Models\Impostazione::get('ente_nome', 'ProntoPA') }}
            </div>
            <div class="titolo">Scheda Segnalazione #{{ $segnalazione->id_segnalazione }}</div>
        </div>
        <div class="header-right">
            @if($segnalazione->stato)
                <div class="stato-badge">{{ strtoupper($segnalazione->stato->descrizione) }}</div>
            @endif
        </div>
    </div>

    {{-- Tipologia --}}
    <div class="tipologia">
        TIPOLOGIA : <span>{{ strtoupper($segnalazione->tipologia?->descrizione ?? '—') }}</span>
    </div>

    {{-- Meta --}}
    <div class="meta-row">
        <div><span class="label">Data segnalazione :</span>
            {{ $segnalazione->data_segnalazione?->format('d-m-y H:i') }}</div>
        <div><span class="label">Provenienza :</span>
            <strong>{{ strtoupper($segnalazione->provenienza?->descrizione ?? '—') }}</strong></div>
    </div>

    {{-- Plesso --}}
    @if($segnalazione->id_plesso && $segnalazione->plesso)
        @php $plesso = $segnalazione->plesso; $istituto = $plesso->istituto; @endphp
        <div class="plesso-block">
            <div><span class="label">PLESSO :</span>
                <span class="plesso-nome">
                    {{ $plesso->nome }}
                    @if($plesso->codice_meccanografico) ({{ $plesso->codice_meccanografico }}) @endif
                </span>
            </div>
            @if($plesso->indirizzo)
                <div class="plesso-indirizzo">{{ $plesso->indirizzo }}</div>
            @endif
            @if($plesso->referente || $plesso->recapiti)
                <div class="plesso-referente">
                    REFERENTE : {{ $plesso->referente }}{{ $plesso->recapiti ? ' - ' . $plesso->recapiti : '' }}
                </div>
            @endif
            @if($istituto && ($istituto->dirigente || $istituto->recapiti))
                <div class="dirigente">
                    <span class="label">Dirigente Scolastico :</span>
                    {{ $istituto->dirigente }}{{ $istituto->recapiti ? ' - ' . $istituto->recapiti : '' }}
                </div>
            @endif
        </div>
    @endif

    {{-- Testo --}}
    <div class="testo-box">
        <div class="testo-label">Testo Segnalazione :</div>
        <div class="testo-content">{{ $segnalazione->testo_segnalazione }}</div>
    </div>

    {{-- Registratore --}}
    @if($segnalazione->utente)
        <div class="registratore">
            Operatore che ha registrato la segnalazione :
            <strong>{{ strtoupper($segnalazione->utente->name) }}</strong>
        </div>
    @endif

    {{-- Storico stati --}}
    <div class="section-title">Evoluzione Segnalazione</div>
    @if($segnalazione->storicoStati->isNotEmpty())
        <table>
            <thead>
                <tr>
                    <th>Registrazione</th>
                    <th>Stato</th>
                    <th>Operatore</th>
                    <th>Impresa</th>
                </tr>
            </thead>
            <tbody>
                @foreach($segnalazione->storicoStati->sortBy('data_registrazione') as $storico)
                    <tr>
                        <td>{{ \Carbon\Carbon::parse($storico->data_registrazione)->format('d-m-Y - H:i') }}</td>
                        <td><strong>{{ strtoupper($storico->stato?->descrizione ?? '—') }}</strong></td>
                        <td>{{ $storico->utente ? strtoupper($storico->utente->name) : '—' }}</td>
                        <td>{{ $segnalazione->appalto?->impresa?->ragione_sociale ?? '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p style="font-size:9.5pt; color:#6b7280; margin-top:4px;">Nessuna evoluzione registrata.</p>
    @endif

    {{-- Note --}}
    <div class="section-title">Note Segnalazione</div>
    @forelse($segnalazione->note->sortBy('data') as $nota)
        <div class="nota">
            <div class="nota-meta">
                {{ \Carbon\Carbon::parse($nota->data)->format('d/m/Y H:i') }}
                — {{ $nota->autore?->name ?? '—' }}
            </div>
            <div>{{ $nota->testo }}</div>
        </div>
    @empty
        <p style="font-size:9.5pt; color:#6b7280; margin-top:4px;">&nbsp;</p>
        <div style="border-bottom:1px solid #ccc; margin:6px 0;"></div>
        <div style="border-bottom:1px solid #ccc; margin:6px 0;"></div>
    @endforelse

    {{-- Footer --}}
    <div class="footer">
        <div>{{ \App\Models\Impostazione::get('ente_nome', 'ProntoPA') }} — ProntoPA</div>
        <div>Stampato il {{ now()->format('d/m/Y H:i') }}</div>
    </div>

    <script>window.onload = function() { window.print(); }</script>
</body>
</html>
