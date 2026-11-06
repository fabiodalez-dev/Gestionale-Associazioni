<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Segnalazione di un messaggio alla moderazione (issue #13): una per socio.
 */
class DiscussioneSegnalazione extends Model
{
    use BelongsToAssociazione;
    use HasUuids;

    protected $table = 'discussioni_segnalazioni';

    protected $fillable = [
        'associazione_id',
        'messaggio_id',
        'socio_id',
        'motivo',
        'gestita_at',
    ];

    protected function casts(): array
    {
        return [
            'gestita_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DiscussioneMessaggio, $this>
     */
    public function messaggio(): BelongsTo
    {
        return $this->belongsTo(DiscussioneMessaggio::class, 'messaggio_id');
    }

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }
}
