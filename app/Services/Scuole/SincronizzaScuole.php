<?php

namespace App\Services\Scuole;

use App\Models\Istituto;
use App\Models\Plesso;
use App\Models\Segnalazione;
use Illuminate\Support\Facades\DB;

/**
 * Scritture su istituti/plessi a partire dall'anagrafe MIUR. La selezione la
 * decide l'admin; per i record selezionati (fonte_dati = miur) i campi MIUR
 * sono fonte di verità. Non cancella mai nulla.
 */
class SincronizzaScuole
{
    public function __construct(private AnagrafeMiur $anagrafe) {}

    /**
     * @param list<string> $codiciSedi
     * @return array{istituto: Istituto, creati: list<string>, aggiornati: list<string>, adottati: list<string>, non_selezionati: list<Plesso>}
     */
    public function applicaSelezione(string $codiceIstituto, array $codiciSedi): array
    {
        $dati = $this->anagrafe->istituto($codiceIstituto);
        if ($dati === null) {
            throw new SedeNonTrovata("Istituto {$codiceIstituto} non presente nell'anagrafe in uso");
        }

        $sediMiur = array_column($dati['sedi'], null, 'codice');
        $codiciSedi = array_values(array_unique(array_map(fn ($c) => strtoupper(trim((string) $c)), $codiciSedi)));
        foreach ($codiciSedi as $codice) {
            if (! isset($sediMiur[$codice])) {
                throw new SedeNonTrovata("Sede {$codice} non appartiene a {$dati['codice']} nell'anagrafe in uso");
            }
        }

        return DB::transaction(function () use ($dati, $sediMiur, $codiciSedi) {
            $esito = ['creati' => [], 'aggiornati' => [], 'adottati' => [], 'non_selezionati' => []];

            $istituto = Istituto::conCodice($dati['codice'])->first() ?? new Istituto(['attivo' => true]);
            $istituto->fill($this->campiIstituto($dati))->save();

            foreach ($codiciSedi as $codice) {
                $plesso = Plesso::conCodice($codice)->first();
                $chiave = match (true) {
                    $plesso === null => 'creati',
                    $plesso->isMiur() => 'aggiornati',
                    default => 'adottati',
                };
                $plesso ??= new Plesso;
                $plesso->fill($this->campiPlesso($sediMiur[$codice]) + ['id_istituto' => $istituto->id_istituto])->save();
                $esito[$chiave][] = $codice;
            }

            $esito['non_selezionati'] = Plesso::where('id_istituto', $istituto->id_istituto)
                ->get()
                ->reject(fn (Plesso $p) => in_array(strtoupper(trim((string) $p->codice_meccanografico)), $codiciSedi, true))
                ->values()
                ->all();

            return ['istituto' => $istituto] + $esito;
        });
    }

    /** @return array{aggiornati: int, da_verificare: list<string>} */
    public function riallinea(): array
    {
        $aggiornati = 0;
        $daVerificare = [];

        Istituto::where('fonte_dati', 'miur')->each(function (Istituto $istituto) use (&$aggiornati) {
            $dati = $this->anagrafe->istituto((string) $istituto->codice_meccanografico);
            if ($dati !== null) {
                $istituto->fill($this->campiIstituto($dati));
                if ($istituto->isDirty()) {
                    $istituto->save();
                    $aggiornati++;
                }
            }
        });

        Plesso::where('fonte_dati', 'miur')->with('istituto')->each(function (Plesso $plesso) use (&$aggiornati, &$daVerificare) {
            $sede = $this->anagrafe->sede((string) $plesso->codice_meccanografico);
            if ($sede === null) {
                return;
            }

            $plesso->fill($this->campiPlesso($sede));

            $codiceAttuale = strtoupper(trim((string) $plesso->istituto?->codice_meccanografico));
            if ($sede['codice_istituto'] !== $codiceAttuale) {
                $nuovo = Istituto::conCodice($sede['codice_istituto'])->first();
                if ($nuovo !== null) {
                    $plesso->id_istituto = $nuovo->id_istituto;
                } else {
                    $daVerificare[] = $sede['codice'];
                }
            }

            if ($plesso->isDirty()) {
                $plesso->save();
                $aggiornati++;
            }
        });

        return ['aggiornati' => $aggiornati, 'da_verificare' => $daVerificare];
    }

    /** @return list<array{tipo: string, id: int, codice: string, nome: string, segnalazioni: int}> */
    public function nonPiuPresenti(): array
    {
        $mancanti = [];

        foreach (Istituto::where('fonte_dati', 'miur')->get() as $istituto) {
            if ($this->anagrafe->istituto((string) $istituto->codice_meccanografico) === null) {
                $mancanti[] = [
                    'tipo' => 'istituto',
                    'id' => $istituto->id_istituto,
                    'codice' => (string) $istituto->codice_meccanografico,
                    'nome' => $istituto->descrizione,
                    'segnalazioni' => Segnalazione::whereIn('id_plesso', $istituto->plessi()->select('id_plesso'))->count(),
                ];
            }
        }

        foreach (Plesso::where('fonte_dati', 'miur')->get() as $plesso) {
            if ($this->anagrafe->sede((string) $plesso->codice_meccanografico) === null) {
                $mancanti[] = [
                    'tipo' => 'sede',
                    'id' => $plesso->id_plesso,
                    'codice' => (string) $plesso->codice_meccanografico,
                    'nome' => (string) $plesso->nome,
                    'segnalazioni' => Segnalazione::where('id_plesso', $plesso->id_plesso)->count(),
                ];
            }
        }

        return $mancanti;
    }

    /** @param array{codice: string, nome: string, email: string} $dati */
    private function campiIstituto(array $dati): array
    {
        return [
            'descrizione' => $dati['nome'],
            'codice_meccanografico' => $dati['codice'],
            'email' => $dati['email'] ?: null,
            'tipo' => 'Scuola',
            'tipo_ente' => 'scuola',
            'fonte_dati' => 'miur',
        ];
    }

    /** @param array<string, mixed> $sede */
    private function campiPlesso(array $sede): array
    {
        return [
            'nome' => $sede['nome'],
            'codice_meccanografico' => $sede['codice'],
            'indirizzo' => trim($sede['indirizzo'].', '.$sede['comune'], ', '),
            'email' => $sede['email'] ?: null,
            'fonte_dati' => 'miur',
        ];
    }
}
