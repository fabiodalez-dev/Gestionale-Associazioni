<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dichiarazione annuale sui conflitti di interesse di chi ricopre una carica
 * (issue #11): nessun conflitto, oppure gli interessi da dichiarare. Riservata
 * a chi gestisce riunioni e verbali.
 */
class DichiarazioneInteressi extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasUuids;

    protected $table = 'dichiarazioni_interessi';

    protected $fillable = [
        'associazione_id',
        'socio_id',
        'anno',
        'nessun_conflitto',
        'interessi',
        'dichiarata_at',
        'registrata_da',
    ];

    protected function casts(): array
    {
        return [
            'anno' => 'integer',
            'nessun_conflitto' => 'boolean',
            'dichiarata_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }
}
