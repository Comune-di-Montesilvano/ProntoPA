<?php

namespace App\Http\Controllers;

use App\Support\Segnalazioni\AllegatiTemporanei;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** Un blocco di una foto caricata prima dell'invio del form (vedi AllegatiTemporanei). */
class AllegatiTemporaneiController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'id' => ['required', 'uuid'],
            'indice' => ['required', 'integer', 'min:0'],
            'totale' => ['required', 'integer', 'min:1', 'max:1000'],
            'nome' => ['nullable', 'string', 'max:255'],
            'blocco' => ['required', 'file'],
        ]);
        if ($v->fails()) {
            return response()->json(['errore' => 'Blocco non valido: riprova.'], 422);
        }

        try {
            $esito = AllegatiTemporanei::ricevi(
                $request->user(),
                (string) $request->input('id'),
                (int) $request->input('indice'),
                (int) $request->input('totale'),
                (string) $request->input('nome', ''),
                $request->file('blocco'),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['errore' => $e->getMessage()], 422);
        }

        return response()->json($esito);
    }

    /** "Togli" su una foto già caricata. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        AllegatiTemporanei::elimina($request->user(), $id);

        return response()->json(['ok' => true]);
    }
}
