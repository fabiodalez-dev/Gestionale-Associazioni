<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Registro "ha votato" di una votazione dell'assemblea (issue #8): il socio
 * presente (votante) e il socio per cui vale il voto (sé stesso o un
 * delegante). L'unique su votazione + rappresentato impedisce il doppio voto.
 */
class VotanteRiunione extends Model
{
    use BelongsToAssociazione;
    use HasUuids;

    protected $table = 'votanti_riunione';

    protected $fillable = [
        'associazione_id',
        'votazione_id',
        'votante_socio_id',
        'rappresentato_socio_id',
    ];
}
