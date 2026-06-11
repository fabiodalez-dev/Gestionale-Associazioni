<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Contatore progressivo tessere per (associazione, anno).
 * Usato da TesseraIssuer per la numerazione unificata {anno}{NNNN}.
 */
class TesseraCounter extends Model
{
    use BelongsToAssociazione;
    use HasUuids;

    protected $table = 'tessera_counters';

    protected $fillable = [
        'associazione_id',
        'anno',
        'ultimo_numero',
    ];

    protected function casts(): array
    {
        return [
            'anno' => 'integer',
            'ultimo_numero' => 'integer',
        ];
    }
}
