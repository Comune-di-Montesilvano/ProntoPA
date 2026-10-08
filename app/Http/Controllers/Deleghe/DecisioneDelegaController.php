<?php

namespace App\Http\Controllers\Deleghe;

use App\Http\Controllers\Controller;
use App\Models\Delega;
use App\Services\Deleghe\DelegaService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/** Pagina della segreteria: senza login, link firmato, decide solo il POST. */
class DecisioneDelegaController extends Controller
{
    public function __construct(private DelegaService $deleghe) {}

    public function show(Request $request, string $token): Response
    {
        $righe = $this->righeValide($request, $token);

        return $righe === null
            ? response()->view('deleghe.link-non-valido', [], 410)
            : response()->view('deleghe.decidi', ['righe' => $righe]);
    }

    public function store(Request $request, string $token): Response
    {
        $righe = $this->righeValide($request, $token);
        if ($righe === null) {
            return response()->view('deleghe.link-non-valido', [], 410);
        }

        $data = $request->validate([
            'azione' => ['required', 'in:approva,rifiuta'],
            'non_conosco' => ['nullable', 'boolean'],
        ]);

        if ($righe->contains(fn (Delega $d) => $d->stato === Delega::RICHIESTA)) {
            if ($data['azione'] === 'approva') {
                $this->deleghe->approva($righe, 'segreteria', null, $request->ip());
            } else {
                $this->deleghe->rifiuta($righe, $request->ip(), (bool) ($data['non_conosco'] ?? false));
            }
        }

        return response()->view('deleghe.decidi', ['righe' => $this->deleghe->daToken($token)]);
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
