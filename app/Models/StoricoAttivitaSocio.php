<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Storico attività del socio (append-only, fail-safe nel legacy).
 * Non usa LogsActivity: è esso stesso una tabella di log.
 */
class StoricoAttivitaSocio extends Model
{
    use BelongsToAssociazione;
    use HasUuids;

    protected $table = 'storico_attivita_socio';

    public $timestamps = false;

    protected $fillable = [
        'associazione_id',
        'socio_id',
        'utente_id',
        'tipo_attivita',
        'descrizione',
        'dettagli_json',
        'data_attivita',
    ];

    protected function casts(): array
    {
        return [
            'dettagli_json' => 'array',
            'data_attivita' => 'datetime',
        ];
    }

    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }

    public function utente(): BelongsTo
    {
        return $this->belongsTo(Utente::class, 'utente_id');
    }
}
