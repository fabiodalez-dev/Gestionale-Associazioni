<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Socio che segue una discussione (issue #13): riceve il riepilogo dei
 * nuovi messaggi arrivati dopo ultimo_riepilogo_at.
 */
class DiscussioneIscrizione extends Model
{
    use BelongsToAssociazione;
    use HasUuids;

    protected $table = 'discussioni_iscrizioni';

    protected $fillable = [
        'associazione_id',
        'discussione_id',
        'socio_id',
        'ultimo_riepilogo_at',
    ];

    protected function casts(): array
    {
        return [
            'ultimo_riepilogo_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Discussione, $this>
     */
    public function discussione(): BelongsTo
    {
        return $this->belongsTo(Discussione::class, 'discussione_id');
    }

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }
}
