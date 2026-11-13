<?php

namespace App\Http\Controllers;

use App\Models\Segnalazione;
use App\Services\SegnalazioneWorkflowService;
use App\Services\Segnalazioni\AzioniMultiple;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Elenco gestione: la stessa azione su più segnalazioni selezionate. */
class AzioniMultipleController extends Controller
{
    public const MASSIMO = 100;

    public function __invoke(Request $request, AzioniMultiple $servizio): RedirectResponse
    {
        $dati = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MASSIMO],
            'ids.*' => ['integer'],
            'operazione' => ['required', Rule::in(AzioniMultiple::OPERAZIONI)],
            'tipo' => ['required_if:operazione,assegna', 'nullable', Rule::in(['me', 'operaio', 'squadra', 'ditta'])],
            'id_operatore' => ['required_if:tipo,operaio', 'nullable', 'integer', 'exists:users,id'],
            'id_squadra' => ['required_if:tipo,squadra', 'nullable', 'integer', 'exists:squadre,id_squadra'],
            'id_appalto' => ['required_if:tipo,ditta', 'nullable', 'integer', 'exists:appalti,id_appalto'],
            'data_scadenza' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'motivo' => ['required_if:operazione,chiudi_senza_intervento', 'nullable', Rule::in(array_keys(SegnalazioneWorkflowService::MOTIVI_SENZA_INTERVENTO))],
            'nota' => [
                Rule::requiredIf(fn () => $request->input('operazione') === 'chiudi_senza_intervento' && $request->input('motivo') === 'altro'),
                'nullable', 'string', 'max:2000',
            ],
            'ritorno' => ['nullable', 'string', 'max:2000'],
        ], [
            'ids.required' => 'Seleziona almeno una segnalazione.',
            'ids.max' => 'Al massimo '.self::MASSIMO.' segnalazioni per volta.',
            'motivo.required_if' => 'Indica perché si chiude senza intervento.',
            'nota.required' => 'Scrivi il motivo.',
            'data_scadenza.after_or_equal' => 'La nuova scadenza non può essere nel passato.',
        ]);

        $segnalazioni = Segnalazione::visibileA($request->user())
            ->whereIn('id_segnalazione', $dati['ids'])
            ->orderBy('id_segnalazione')
            ->get();

        $esito = $servizio->esegui($segnalazioni, $request->user(), $dati['operazione'], $dati);

        $messaggio = $esito['fatte'].' fatte';
        if ($esito['saltate']) {
            $messaggio .= ', '.count($esito['saltate']).' saltate: '.collect($esito['saltate'])
                ->take(10)->map(fn (string $motivo, int $id) => "#{$id}: {$motivo}")->implode('; ');
        }

        return redirect()->to($this->ritorno($dati['ritorno'] ?? null))->with('success', $messaggio);
    }

    /** Torna all'elenco con gli stessi filtri: accetta solo una query string, mai un altro URL. */
    private function ritorno(?string $query): string
    {
        $base = route('gestione.dashboard');
        $query = ltrim((string) $query, '?');

        return $query !== '' && ! preg_match('#[/\\\\:]#', $query) ? $base.'?'.$query : $base;
    }
}
