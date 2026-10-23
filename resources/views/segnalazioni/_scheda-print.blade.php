{{-- Corpo della scheda stampata per $segnalazione (singola o aggregata). --}}

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

