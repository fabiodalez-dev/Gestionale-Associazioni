<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ricevuta di pagamento quota (f.21 del piano): una sola ricevuta per
 * quota (unique a DB), numerazione progressiva per tenant e anno
 * (unique associazione+anno+numero), es. "12/2026".
 */
class Ricevuta extends Model
{
    use BelongsToAssociazione;
    use HasFactory;
    use HasUuids;

    protected $table = 'ricevute';

    protected $fillable = [
        'associazione_id',
        'quota_id',
        'numero',
        'anno',
        'importo',
        'emessa_at',
    ];

    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'anno' => 'integer',
            'importo' => 'decimal:2',
            'emessa_at' => 'datetime',
        ];
    }

    /**
     * Numero completo della ricevuta, es. "12/2026".
     */
    public function getNumeroCompletoAttribute(): string
    {
        return $this->numero.'/'.$this->anno;
    }

    /**
     * @return BelongsTo<Quota, $this>
     */
    public function quota(): BelongsTo
    {
        return $this->belongsTo(Quota::class, 'quota_id');
    }
}
