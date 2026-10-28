<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Passo di una checklist del manuale (issue #12): chi l'ha spuntato, quando
 * e con quali note.
 */
class ManualeEsecuzionePasso extends Model
{
    use BelongsToAssociazione;
    use HasUuids;

    protected $table = 'manuale_esecuzioni_passi';

    protected $fillable = [
        'associazione_id',
        'esecuzione_id',
        'indice',
        'testo',
        'completato_at',
        'completato_da',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'indice' => 'integer',
            'completato_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Utente, $this>
     */
    public function completatoDa(): BelongsTo
    {
        return $this->belongsTo(Utente::class, 'completato_da');
    }
}
