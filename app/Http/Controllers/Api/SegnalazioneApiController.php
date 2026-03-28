<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiLog;
use App\Models\Segnalazione;
use App\Models\StatoSegnalazione;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SegnalazioneApiController extends Controller
{
    /**
     * POST /api/segnalazioni
     *
     * Crea una nuova segnalazione ricevuta dal sito web del Comune (portale cittadini).
     * Autenticazione: Sanctum token (Bearer).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_tipologia_segnalazione' => ['required', 'integer', 'exists:tipologie_segnalazioni,id_tipologia_segnalazione'],
            'testo_segnalazione'        => ['required', 'string', 'max:2000'],
            'id_provenienza'            => ['required', 'integer', 'exists:provenienze_segnalazioni,id_provenienza'],
            'id_plesso'                 => ['nullable', 'integer', 'exists:plessi,id_plesso'],
            'segnalante'                => ['nullable', 'string', 'max:100'],
            'email'                     => ['nullable', 'email', 'max:100'],
            'telefono'                  => ['nullable', 'string', 'max:50'],
            'latitudine'                => ['nullable', 'numeric'],
            'longitudine'               => ['nullable', 'numeric'],
        ]);

        $statoIniziale = StatoSegnalazione::where('iniziale', true)->first();

        $segnalazione = Segnalazione::create(array_merge($data, [
            'id_utente_segnalazione' => $request->user()?->id,
            'id_stato_segnalazione'  => $statoIniziale?->id_stato ?? 1,
            'id_plesso'              => $data['id_plesso'] ?? 0,
            'latitudine'             => $data['latitudine'] ?? 0,
            'longitudine'            => $data['longitudine'] ?? 0,
        ]));

        ApiLog::logInbound(
            endpoint: '/api/segnalazioni',
            payload: $data,
            status: 201,
            response: ['id_segnalazione' => $segnalazione->id_segnalazione],
            segnalazioneId: $segnalazione->id_segnalazione,
        );

        return response()->json([
            'id_segnalazione' => $segnalazione->id_segnalazione,
            'stato'           => $statoIniziale?->descrizione ?? 'In attesa di esame',
        ], 201);
    }

    /**
     * GET /api/segnalazioni/{id}/stato
     *
     * Restituisce lo stato corrente di una segnalazione al sito del Comune.
     * Autenticazione: Sanctum token (Bearer).
     */
    public function stato(int $id): JsonResponse
    {
        $segnalazione = Segnalazione::with('stato')->findOrFail($id);

        return response()->json([
            'id_segnalazione' => $segnalazione->id_segnalazione,
            'stato'           => [
                'id'          => $segnalazione->stato?->id_stato,
                'descrizione' => $segnalazione->stato?->descrizione,
            ],
            'data_segnalazione'  => $segnalazione->data_segnalazione?->toIso8601String(),
            'data_chiusura'      => $segnalazione->data_chiusura,
            'flag_evidenza'      => (bool) $segnalazione->flag_evidenza,
        ]);
    }
}
