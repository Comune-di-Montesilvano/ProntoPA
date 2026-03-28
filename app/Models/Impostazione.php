<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Impostazione extends Model
{
    protected $table      = 'impostazioni';
    protected $primaryKey = 'chiave';
    protected $keyType    = 'string';
    public    $incrementing = false;

    protected $fillable = [
        'chiave',
        'valore',
        'tipo',
        'gruppo',
        'descrizione',
    ];

    /**
     * Legge un'impostazione dalla cache (o dal DB se non cachata).
     */
    public static function get(string $chiave, mixed $default = null): mixed
    {
        return Cache::rememberForever(
            "impostazione:{$chiave}",
            fn () => static::find($chiave)?->valore ?? $default
        );
    }

    /**
     * Aggiorna un'impostazione e invalida la cache.
     */
    public static function set(string $chiave, mixed $valore): void
    {
        static::updateOrCreate(
            ['chiave' => $chiave],
            ['valore' => $valore]
        );
        Cache::forget("impostazione:{$chiave}");
    }

    /**
     * Restituisce tutte le impostazioni di un gruppo, indicizzate per chiave.
     */
    public static function gruppo(string $gruppo): array
    {
        return static::where('gruppo', $gruppo)
            ->pluck('valore', 'chiave')
            ->all();
    }

    /**
     * Invalida la cache dell'impostazione quando viene salvata.
     */
    protected static function booted(): void
    {
        static::saved(function (self $model) {
            Cache::forget("impostazione:{$model->chiave}");
        });
    }
}
