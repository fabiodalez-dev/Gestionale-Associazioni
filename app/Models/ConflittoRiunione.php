<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Conflitto di interesse dichiarato su un punto dell'OdG (issue #11): il
 * socio non vota su quel punto (né di persona né per delega) e la
 * dichiarazione compare nel verbale.
 */
class ConflittoRiunione extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasUuids;

    protected $table = 'conflitti_riunione';

    protected $fillable = [
        'associazione_id',
        'riunione_id',
        'punto_indice',
        'socio_id',
        'motivazione',
    ];

    protected function casts(): array
    {
        return [
            'punto_indice' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Riunione, $this>
     */
    public function riunione(): BelongsTo
    {
        return $this->belongsTo(Riunione::class, 'riunione_id');
    }

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }
}
