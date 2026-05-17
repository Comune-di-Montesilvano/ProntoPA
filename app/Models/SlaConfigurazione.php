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
        'id_specializzazione',
        'livello_priorita',
        'ore_target',
        'ore_warning',
        'descrizione',
    ];

    protected function casts(): array
    {
        return [
            'livello_priorita' => 'integer',
            'ore_target'       => 'integer',
            'ore_warning'      => 'integer',
        ];
    }

    public function tipologia(): BelongsTo
    {
        return $this->belongsTo(TipologiaSegnalazione::class, 'id_tipologia_segnalazione', 'id_tipologia_segnalazione');
    }

    public function specializzazione(): BelongsTo
    {
        return $this->belongsTo(Specializzazione::class, 'id_specializzazione', 'id_specializzazione');
    }

    /**
     * Trova la regola SLA applicabile con fallback:
     * 1. match esatto (tipologia + specializzazione + priorità)
     * 2. solo tipologia + priorità
     * 3. default (nessuna tipologia) + priorità
     */
    public static function applicabileA(
        ?int $idTipologia,
        ?int $idSpecializzazione,
        int  $livelloPriorita
    ): ?self {
        // Tentativo 1: match esatto
        if ($idTipologia && $idSpecializzazione) {
            $regola = self::where('id_tipologia_segnalazione', $idTipologia)
                ->where('id_specializzazione', $idSpecializzazione)
                ->where('livello_priorita', $livelloPriorita)
                ->first();
            if ($regola) return $regola;
        }

        // Tentativo 2: solo tipologia
        if ($idTipologia) {
            $regola = self::where('id_tipologia_segnalazione', $idTipologia)
                ->whereNull('id_specializzazione')
                ->where('livello_priorita', $livelloPriorita)
                ->first();
            if ($regola) return $regola;
        }

        // Tentativo 3: regola default
        return self::whereNull('id_tipologia_segnalazione')
            ->whereNull('id_specializzazione')
            ->where('livello_priorita', $livelloPriorita)
            ->first();
    }
}
