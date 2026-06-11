<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedFilter extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasUuids;

    protected $table = 'saved_filters';

    protected $fillable = [
        'associazione_id',
        'user_id',
        'scope',
        'name',
        'params_json',
    ];

    protected function casts(): array
    {
        return [
            'params_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Utente, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Utente::class, 'user_id');
    }

    /**
     * Filtro condiviso con tutto il tenant (user_id NULL).
     */
    public function isCondiviso(): bool
    {
        return $this->user_id === null;
    }
}
