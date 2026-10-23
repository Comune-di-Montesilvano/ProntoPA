{{-- Stili della scheda di stampa: usati da print e print-multiple. --}}
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
