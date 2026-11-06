<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Messaggio inviato tramite la rubrica (issue #14): il testo non si
 * conserva, restano mittente, destinatario, oggetto e l'eventuale
 * segnalazione di uso improprio.
 */
class RubricaContatto extends Model
{
    use BelongsToAssociazione;
    use HasUuids;

    protected $table = 'rubrica_contatti';

    protected $fillable = [
        'associazione_id',
        'mittente_socio_id',
        'destinatario_socio_id',
        'oggetto',
        'segnalato_at',
    ];

    protected function casts(): array
    {
        return [
            'segnalato_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function mittente(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'mittente_socio_id');
    }

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function destinatario(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'destinatario_socio_id');
    }
}
