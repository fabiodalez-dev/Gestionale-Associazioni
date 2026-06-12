<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Categoria di movimento della prima nota (f.13 del piano "Gestione 2"):
 * seedata per tenant (es. Quote, Eventi, Donazioni, Utenze, ...),
 * tipizzata entrata/uscita.
 */
class CategoriaMovimento extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;

    public const TIPO_ENTRATA = 'entrata';

    public const TIPO_USCITA = 'uscita';

    public const TIPI = [
        self::TIPO_ENTRATA,
        self::TIPO_USCITA,
    ];

    protected $table = 'categorie_movimento';

    protected $fillable = [
        'associazione_id',
        'nome',
        'tipo',
    ];

    /**
     * @return HasMany<Movimento, $this>
     */
    public function movimenti(): HasMany
    {
        return $this->hasMany(Movimento::class, 'categoria_id');
    }
}
