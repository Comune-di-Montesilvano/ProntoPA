<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlaConfigurazione extends Model
{
    protected $table      = 'sla_configurazioni';
    protected $primaryKey = 'id_sla';
    public    $timestamps = false;

    protected $fillable = [
        'id_tipologia_segnalazione',
        'livello_priorita',
        'ore_target',
        'unita',
        'ore_warning',
        'giorni_avviso_inattivita',
        'giorni_archiviazione_inattivita',
        'descrizione',
    ];

    protected function casts(): array
    {
        return [
            'livello_priorita' => 'integer',
            'ore_target'       => 'integer',
            'ore_warning'      => 'integer',
            'giorni_avviso_inattivita'        => 'integer',
            'giorni_archiviazione_inattivita' => 'integer',
        ];
    }

    /** "4 ore lavorative", "1 giorno lavorativo": il tempo della regola in parole. */
    public function descrizioneTempo(): string
    {
        $n = (int) $this->ore_target;

        return $this->unita === 'giorni'
            ? ($n === 1 ? '1 giorno lavorativo' : "$n giorni lavorativi")
            : ($n === 1 ? '1 ora lavorativa' : "$n ore lavorative");
    }

    public function tipologia(): BelongsTo
    {
        return $this->belongsTo(TipologiaSegnalazione::class, 'id_tipologia_segnalazione', 'id_tipologia_segnalazione');
    }


    /**
     * Regola applicabile: tipologia + priorità, altrimenti quella senza tipologia (stessa priorità).
     * 1.0.0: niente più specializzazioni.
     */
    public static function applicabileA(?int $idTipologia, int $livelloPriorita): ?self
    {
        if ($idTipologia) {
            $regola = self::where('id_tipologia_segnalazione', $idTipologia)
                ->where('livello_priorita', $livelloPriorita)
                ->first();
            if ($regola) {
                return $regola;
            }
        }

        return self::whereNull('id_tipologia_segnalazione')
            ->where('livello_priorita', $livelloPriorita)
            ->first();
    }
}
