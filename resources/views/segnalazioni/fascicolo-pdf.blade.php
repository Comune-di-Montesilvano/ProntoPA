<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Fascicolo #{{ $segnalazione->id_segnalazione }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9pt; color: #111; padding: 12px 16px; }

        /* Header */
        .header { border-bottom: 2px solid #1f2937; padding-bottom: 6px; margin-bottom: 10px; }
        .header-ente { font-size: 8pt; color: #6b7280; text-transform: uppercase; letter-spacing: .05em; }
        .header-title { font-size: 14pt; font-weight: bold; }
        .header-stato { float: right; background: #1f2937; color: #fff; padding: 3px 8px; border-radius: 4px; font-size: 8pt; font-weight: bold; }

        /* Sezioni */
        .section-title { font-size: 9pt; font-weight: bold; text-transform: uppercase; letter-spacing: .04em; border-bottom: 1px solid #d1d5db; padding-bottom: 2px; margin: 10px 0 5px; color: #374151; }

        /* Griglia metadati */
        .meta-table { width: 100%; border-collapse: collapse; }
        .meta-table td { padding: 2px 4px; font-size: 9pt; vertical-align: top; }
        .meta-table td.label { font-weight: bold; color: #4b5563; width: 32%; white-space: nowrap; }

        /* Testo segnalazione */
        .testo-box { border: 1px solid #d1d5db; border-radius: 3px; padding: 6px 8px; font-style: italic; font-size: 9pt; line-height: 1.5; margin: 4px 0; }

        /* Tabelle storico e note */
        table.lista { width: 100%; border-collapse: collapse; font-size: 8.5pt; }
        table.lista thead td, table.lista thead th { background: #1f2937; color: #fff; padding: 4px 6px; font-weight: bold; }
        table.lista tbody td { padding: 3px 6px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        table.lista tbody tr:nth-child(even) td { background: #f9fafb; }

        /* Footer */
        .footer { border-top: 1px solid #e5e7eb; margin-top: 14px; padding-top: 5px; font-size: 7.5pt; color: #9ca3af; }
    </style>
</head>
<body>

{{-- Header --}}
<div class="header">
    @if($segnalazione->stato)
        <div class="header-stato">{{ strtoupper($segnalazione->stato->descrizione) }}</div>
    @endif
    <div class="header-ente">{{ $nomeEnte }} — Fascicolo segnalazione</div>
    <div class="header-title">Segnalazione #{{ $segnalazione->id_segnalazione }}</div>
</div>

{{-- Metadati --}}
<div class="section-title">Dati principali</div>
<table class="meta-table">
    <tr>
        <td class="label">Data:</td>
        <td>{{ $segnalazione->data_segnalazione?->format('d/m/Y H:i') ?? '—' }}</td>
        <td class="label">Tipologia:</td>
        <td>{{ $segnalazione->tipologia?->descrizione ?? '—' }}</td>
    </tr>
    <tr>
        <td class="label">Priorità:</td>
        <td>{{ $segnalazione->label_priorita }}</td>
        <td class="label">Provenienza:</td>
        <td>{{ $segnalazione->provenienza?->descrizione ?? '—' }}</td>
    </tr>
    <tr>
        <td class="label">Sede / Plesso:</td>
        <td>{{ $segnalazione->plesso?->nome ?? '—' }}
            @if($segnalazione->plesso?->istituto) — {{ $segnalazione->plesso->istituto->nome }} @endif
        </td>
        <td class="label">Operatore:</td>
        <td>{{ $segnalazione->operatore?->name ?? '—' }}</td>
    </tr>
    @if($segnalazione->appalto?->impresa)
    <tr>
        <td class="label">Impresa:</td>
        <td colspan="3">{{ $segnalazione->appalto->impresa->ragione_sociale }}</td>
    </tr>
    @endif
    @if($segnalazione->data_chiusura)
    <tr>
        <td class="label">Data chiusura:</td>
        <td colspan="3">{{ $segnalazione->data_chiusura->format('d/m/Y H:i') }}</td>
    </tr>
    @endif
</table>

{{-- Testo --}}
<div class="section-title">Testo segnalazione</div>
<div class="testo-box">{{ $segnalazione->testo_segnalazione }}</div>

{{-- Storico stati --}}
@if($segnalazione->storicoStati->isNotEmpty())
<div class="section-title">Evoluzione</div>
<table class="lista">
    <thead>
        <tr>
            <td>Data</td>
            <td>Stato</td>
            <td>Operatore</td>
        </tr>
    </thead>
    <tbody>
        @foreach($segnalazione->storicoStati->sortBy('data_registrazione') as $storico)
        <tr>
            <td style="white-space:nowrap">{{ $storico->data_registrazione?->format('d/m/Y H:i') }}</td>
            <td>{{ $storico->stato?->descrizione ?? '—' }}</td>
            <td>{{ $storico->utente?->name ?? '—' }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

{{-- Note (autore relation, data timestamp) --}}
@if($segnalazione->note->isNotEmpty())
<div class="section-title">Note</div>
@foreach($segnalazione->note->sortBy('data') as $nota)
<table class="meta-table" style="margin-bottom:4px;">
    <tr>
        <td class="label" style="width:auto;white-space:nowrap">{{ $nota->data?->format('d/m/Y H:i') }} — {{ $nota->autore?->name ?? '—' }}</td>
    </tr>
    <tr>
        <td>{{ $nota->testo }}</td>
    </tr>
</table>
@endforeach
@endif

{{-- Allegati --}}
@if($segnalazione->allegati->isNotEmpty())
<div class="section-title">Allegati ({{ $segnalazione->allegati->count() }})</div>
@foreach($segnalazione->allegati as $allegato)
<div style="font-size:8.5pt;padding:2px 0;border-bottom:1px solid #f3f4f6;">
    {{ $allegato->nome_originale ?? basename($allegato->percorso ?? '') }}
</div>
@endforeach
@endif

{{-- Footer --}}
<div class="footer">
    Generato il {{ now()->format('d/m/Y H:i') }} &nbsp;·&nbsp; {{ $nomeEnte }} &nbsp;·&nbsp; ProntoPA
</div>

</body>
</html>
