<?php

namespace App\Http\Controllers\Organizzazione;

use App\Http\Controllers\Controller;
use App\Models\TipologiaSegnalazione;
use App\Models\User;
use App\Services\Segnalazioni\CoperturaTipologie;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Di quali tipologie si occupa ogni gestore (non supervisore). */
class TipologieGestoriController extends Controller
{
    public function index(CoperturaTipologie $copertura): View
    {
        $gestori = $this->gestori()->with('tipologieGestite')->get();
        $tipologie = $this->tipologie();

        return view('organizzazione.tipologie-gestori.index', [
            'gestori' => $gestori,
            'tipologie' => $tipologie,
            // Per ogni tipologia chi la copre davvero (attivi con email)
            'copertura' => $tipologie->mapWithKeys(fn (TipologiaSegnalazione $t) => [
                $t->id_tipologia_segnalazione => $copertura->gestoriDi($t->id_tipologia_segnalazione),
            ]),
            ...$this->datiOrfane($copertura),
        ]);
    }

    public function edit(User $utente): View
    {
        $this->soloGestori($utente);

        return view('organizzazione.tipologie-gestori.edit', [
            'utente' => $utente,
            'tipologie' => $this->tipologie(),
            'selezionate' => $utente->tipologieGestite()->pluck('tipologie_segnalazioni.id_tipologia_segnalazione')->map(fn ($id) => (int) $id)->all(),
        ]);
    }

    public function update(Request $request, User $utente): RedirectResponse
    {
        $this->soloGestori($utente);

        $data = $request->validate([
            'tutte' => ['nullable', 'boolean'],
            'tipologie' => ['nullable', 'array'],
            'tipologie.*' => ['integer', 'exists:tipologie_segnalazioni,id_tipologia_segnalazione'],
        ]);

        $utente->forceFill(['gestore_tutte_tipologie' => (bool) ($data['tutte'] ?? false)])->save();
        $utente->tipologieGestite()->sync($data['tipologie'] ?? []);

        return redirect()->route('organizzazione.tipologie-gestori.index')
            ->with('success', "Tipologie di {$utente->name} aggiornate.");
    }

    /**
     * Dati del riquadro "Tipologie senza gestore", condivisi con dashboard e scheda.
     *
     * @return array{orfane: Collection<int, TipologiaSegnalazione>, nonVerificati: array<int, \Illuminate\Support\Collection<int, User>>}
     */
    public static function datiOrfane(CoperturaTipologie $copertura): array
    {
        $orfane = $copertura->orfane();

        return [
            'orfane' => $orfane,
            'nonVerificati' => $orfane->mapWithKeys(fn (TipologiaSegnalazione $t) => [
                $t->id_tipologia_segnalazione => $copertura->nonVerificati($t->id_tipologia_segnalazione),
            ])->all(),
        ];
    }

    /** @return \Illuminate\Database\Eloquent\Builder<User> */
    private function gestori(): \Illuminate\Database\Eloquent\Builder
    {
        return User::role('gestore')
            ->where('supervisore_segnalazioni', false)
            ->where(fn ($q) => $q->where('attivo', true)->orWhereNull('attivo'))
            ->orderBy('name');
    }

    /** @return Collection<int, TipologiaSegnalazione> */
    private function tipologie(): Collection
    {
        return TipologiaSegnalazione::with('gruppo')->orderBy('id_gruppo')->orderBy('descrizione')->get();
    }

    /** I supervisori vedono già tutto: non hanno tipologie. */
    private function soloGestori(User $utente): void
    {
        abort_unless($utente->hasRole('gestore') && ! $utente->isSupervisore(), 404);
    }
}
