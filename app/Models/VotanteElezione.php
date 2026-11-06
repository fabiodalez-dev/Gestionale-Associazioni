<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Registro "ha votato" di un'elezione (issue #9): socio presente e socio per
 * cui vale la scheda (sé stesso o un delegante). Unique contro il doppio voto.
 */
class VotanteElezione extends Model
{
    use BelongsToAssociazione;
    use HasUuids;

    protected $table = 'votanti_elezione';

    protected $fillable = [
        'associazione_id',
        'elezione_id',
        'votante_socio_id',
        'rappresentato_socio_id',
    ];
}
