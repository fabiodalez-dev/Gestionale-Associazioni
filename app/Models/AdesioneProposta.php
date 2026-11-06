<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Adesione di un socio a una proposta (issue #10): una per socio, revocabile.
 */
class AdesioneProposta extends Model
{
    use BelongsToAssociazione;
    use HasUuids;

    protected $table = 'adesioni_proposta';

    protected $fillable = [
        'associazione_id',
        'proposta_id',
        'socio_id',
    ];

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }
}
