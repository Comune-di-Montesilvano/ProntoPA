<?php

namespace App\Models;

use App\Support\Scuole\CategoriaIniziale;
use Database\Factories\CategoriaEnteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CategoriaEnte extends Model
{
    /** @use HasFactory<CategoriaEnteFactory> */
    use HasFactory;

    protected $table = 'categorie_enti';
    protected $primaryKey = 'id_categoria';

    protected $fillable = ['nome', 'ordine', 'import_miur'];

    protected function casts(): array
    {
        return ['import_miur' => 'boolean', 'ordine' => 'integer'];
    }

    public function istituti(): HasMany
    {
        return $this->hasMany(Istituto::class, 'id_categoria', 'id_categoria');
    }

    /** Destinazione degli istituti importati dal MIUR; se manca crea (o riusa) "Scuole statali" e la marca. */
    public static function perImportMiur(): self
    {
        $categoria = self::where('import_miur', true)->orderBy('id_categoria')->first();
        if ($categoria) {
            return $categoria;
        }

        $categoria = self::firstOrCreate(
            ['nome' => CategoriaIniziale::MIUR],
            ['ordine' => (int) self::max('ordine') + 1],
        );
        $categoria->update(['import_miur' => true]);

        return $categoria;
    }
}
