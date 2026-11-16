<?php

namespace App\Models;

use App\Services\Sla\CalendarioUfficio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Domanda del comune a chi ha segnalato: mentre è aperta la segnalazione è in stato 11 e la scadenza è ferma.
 *
 * @property int $id
 * @property int $id_segnalazione
 * @property int $id_utente
 * @property string $testo
 * @property int $stato_precedente
 * @property \Illuminate\Support\Carbon $termine_at
 * @property bool $chiudi_senza_risposta
 * @property \Illuminate\Support\Carbon|null $promemoria_at
 * @property \Illuminate\Support\Carbon|null $conclusa_at
 * @property string|null $esito
 * @property int|null $id_nota_risposta
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class RichiestaInformazioni extends Model
{
    protected $table = 'richieste_informazioni';

    public const UPDATED_AT = null;

    public const ESITO_RISPOSTA = 'risposta';

    public const ESITO_RITIRATA = 'ritirata';

    public const ESITO_CHIUSA = 'chiusa';

    protected $fillable = [
        'id_segnalazione', 'id_utente', 'testo', 'stato_precedente', 'termine_at', 'chiudi_senza_risposta',
        'promemoria_at', 'conclusa_at', 'esito', 'id_nota_risposta',
    ];

    protected function casts(): array
    {
        return [
            'termine_at' => 'datetime',
            'promemoria_at' => 'datetime',
            'conclusa_at' => 'datetime',
            'created_at' => 'datetime',
            'chiudi_senza_risposta' => 'boolean',
            'stato_precedente' => 'integer',
        ];
    }

    /** @param  Builder<self>  $query */
    public function scopeAperte(Builder $query): Builder
    {
        return $query->whereNull('conclusa_at');
    }

    /** @return BelongsTo<Segnalazione, $this> */
    public function segnalazione(): BelongsTo
    {
        return $this->belongsTo(Segnalazione::class, 'id_segnalazione', 'id_segnalazione');
    }

    /** @return BelongsTo<User, $this> */
    public function richiedente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_utente');
    }

    /** Ore lavorative passate ad aspettare risposta (richieste concluse): spostano la scadenza. */
    public static function oreDiAttesa(Segnalazione $s, CalendarioUfficio $calendario): float
    {
        return (float) self::where('id_segnalazione', $s->id_segnalazione)->whereNotNull('conclusa_at')->get()
            ->sum(fn (self $r) => $r->created_at && $r->conclusa_at && $r->conclusa_at->gt($r->created_at)
                ? $calendario->oreLavorativeTra($r->created_at, $r->conclusa_at)
                : 0.0);
    }
}
