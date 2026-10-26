<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stato del socio nelle discussioni (issue #13): regolamento accettato e
 * sospensione dalla scrittura decisa dalla moderazione.
 */
class DiscussioneProfilo extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasUuids;

    protected $table = 'discussioni_profili';

    protected $fillable = [
        'associazione_id',
        'socio_id',
        'regolamento_accettato_at',
        'sospeso_at',
        'sospeso_motivo',
    ];

    protected function casts(): array
    {
        return [
            'regolamento_accettato_at' => 'datetime',
            'sospeso_at' => 'datetime',
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
