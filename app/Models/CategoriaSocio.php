<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CategoriaSocio extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasUuids;

    protected $table = 'categorie_socio';

    protected $fillable = [
        'associazione_id',
        'nome',
        'descrizione',
        'testo_tessera',
    ];

    public function soci(): HasMany
    {
        return $this->hasMany(Socio::class, 'categoria_socio_id');
    }
}
