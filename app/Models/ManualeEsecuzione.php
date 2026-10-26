<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Checklist avviata da una pagina del manuale (issue #12), con i passi
 * copiati al momento dell'avvio. Completata quando tutti i passi sono spuntati.
 */
class ManualeEsecuzione extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasUuids;

    protected $table = 'manuale_esecuzioni';

    protected $fillable = [
        'associazione_id',
        'pagina_id',
        'titolo',
        'avviata_da',
        'completata_at',
    ];

    protected function casts(): array
    {
        return [
            'completata_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ManualePagina, $this>
     */
    public function pagina(): BelongsTo
    {
        return $this->belongsTo(ManualePagina::class, 'pagina_id');
    }

    /**
     * @return BelongsTo<Utente, $this>
     */
    public function avviataDa(): BelongsTo
    {
        return $this->belongsTo(Utente::class, 'avviata_da');
    }

    /**
     * @return HasMany<ManualeEsecuzionePasso, $this>
     */
    public function passi(): HasMany
    {
        return $this->hasMany(ManualeEsecuzionePasso::class, 'esecuzione_id')->orderBy('indice');
    }
}
