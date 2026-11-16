<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

#[\Illuminate\Database\Eloquent\Attributes\ObservedBy(\App\Observers\NotaSegnalazioneObserver::class)]
class NotaSegnalazione extends Model
{
    protected $table      = 'note_segnalazioni';
    protected $primaryKey = 'id_nota';

    const CREATED_AT = 'data';
    const UPDATED_AT = null;

    public const ORIGINE_SCADENZA = 'scadenza';

    public const ORIGINE_RISEGNALATA = 'risegnalata';

    public const ORIGINE_SOLLECITO = 'sollecito';

    public const ORIGINE_INTEGRAZIONE = 'integrazione';

    public const ORIGINE_RIMANDO = 'rimando';

    public const ORIGINE_PRIORITA = 'priorita';

    public const ORIGINE_SENZA_INTERVENTO = 'senza_intervento';

    public const ORIGINE_RICHIESTA = 'richiesta';

    /** Prefisso con cui ogni nota automatica comincia (backfill e visualizzazione). */
    public const PREFISSI = [
        self::ORIGINE_SCADENZA => 'Scadenza spostata al ',
        self::ORIGINE_RISEGNALATA => 'Segnalata di nuovo da ',
        self::ORIGINE_SOLLECITO => 'Sollecito: ',
        self::ORIGINE_RIMANDO => 'Rimandata al comune: ',
        self::ORIGINE_PRIORITA => 'Priorità aumentata a livello ',
        self::ORIGINE_SENZA_INTERVENTO => 'Chiusa senza intervento: ',
    ];

    protected $fillable = [
        'id_segnalazione', 'testo', 'id_utente', 'visibile_web', 'visibile_impresa', 'origine',
    ];

    public static function origineDaTesto(string $testo): ?string
    {
        foreach (self::PREFISSI as $origine => $prefisso) {
            if (str_starts_with($testo, $prefisso)) {
                return $origine;
            }
        }

        return null;
    }

    /** Valorizza `origine` delle note senza, riconoscendole dal prefisso. Restituisce quante. */
    public static function riempiOrigini(): int
    {
        $aggiornate = 0;
        DB::table('note_segnalazioni')->whereNull('origine')->select(['id_nota', 'testo'])
            ->chunkById(500, function ($note) use (&$aggiornate) {
                foreach ($note as $nota) {
                    $origine = self::origineDaTesto((string) $nota->testo);
                    if ($origine !== null) {
                        DB::table('note_segnalazioni')->where('id_nota', $nota->id_nota)->update(['origine' => $origine]);
                        $aggiornate++;
                    }
                }
            }, 'id_nota');

        return $aggiornate;
    }

    /** Il testo senza il prefisso automatico ("Sollecito: …" → "…"); le note libere restano intere. */
    public function testoSenzaPrefisso(): string
    {
        $testo = (string) $this->testo;
        $prefisso = self::PREFISSI[$this->origine ?? ''] ?? null;

        return $prefisso !== null && str_starts_with($testo, $prefisso)
            ? ltrim(substr($testo, strlen($prefisso)))
            : $testo;
    }

    protected function casts(): array
    {
        return [
            'data'             => 'datetime',
            'visibile_web'     => 'boolean',
            'visibile_impresa' => 'boolean',
        ];
    }

    public function autore(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_utente');
    }

    public function segnalazione(): BelongsTo
    {
        return $this->belongsTo(Segnalazione::class, 'id_segnalazione', 'id_segnalazione');
    }

    /**
     * Note che l'utente può leggere. Il personale del comune le vede tutte;
     * la ditta quelle "visibile impresa" e quelle scritte dalla propria ditta;
     * chiunque altro (segnalanti) quelle "visibile web" e le proprie.
     *
     * @param  Builder<NotaSegnalazione>  $query
     */
    public function scopeVisibiliA(Builder $query, User $user): void
    {
        if ($user->isAdmin() || $user->isGestore() || $user->hasAnyRole(['admin', 'gestore', 'operaio'])) {
            return;
        }

        if ($user->hasRole('impresa')) {
            $query->where(function (Builder $q) use ($user) {
                $q->where('visibile_impresa', true)
                    ->orWhere('id_utente', $user->id);
                if ($user->id_impresa) {
                    $q->orWhereIn('id_utente', User::where('id_impresa', $user->id_impresa)->select('id'));
                }
            });

            return;
        }

        $query->where(fn (Builder $q) => $q->where('visibile_web', true)->orWhere('id_utente', $user->id));
    }
}
