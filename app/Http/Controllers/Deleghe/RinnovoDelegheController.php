<?php

namespace App\Http\Controllers\Deleghe;

use App\Http\Controllers\Controller;
use App\Models\Delega;
use App\Services\Deleghe\DelegaService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/** Rinnovo annuale: una riga per persona, nessun "conferma tutti". */
class RinnovoDelegheController extends Controller
{
    public function __construct(private DelegaService $deleghe) {}

    public function show(Request $request, string $token): Response
    {
        $righe = $this->righeValide($request, $token);

        return $righe === null
            ? response()->view('deleghe.link-non-valido', [], 410)
            : response()->view('deleghe.rinnovo', ['righe' => $righe]);
    }

    public function store(Request $request, string $token): Response
    {
        $righe = $this->righeValide($request, $token);
        if ($righe === null) {
            return response()->view('deleghe.link-non-valido', [], 410);
        }

        $data = $request->validate([
            'delega' => ['required', 'integer'],
            'azione' => ['required', 'in:conferma,revoca'],
        ]);

        $delega = $righe->firstWhere('id', (int) $data['delega']);
        abort_if($delega === null, 404);

        if ($data['azione'] === 'conferma') {
            $this->deleghe->confermaRinnovo($delega, $request->ip());
        } elseif ($delega->stato === Delega::ATTIVA && $delega->rinnovo_inviato_at !== null) {
            $this->deleghe->revoca($delega, 'segreteria', null, 'non confermata al rinnovo', $request->ip());
        }

        return response()->view('deleghe.rinnovo', ['righe' => $this->deleghe->daToken($token)]);
    }

    /** @return Collection<int, Delega>|null */
    private function righeValide(Request $request, string $token): ?Collection
    {
        if (! $request->hasValidSignature()) {
            return null;
        }

        $righe = $this->deleghe->daToken($token);

        return $righe->isEmpty() ? null : $righe;
    }
}
