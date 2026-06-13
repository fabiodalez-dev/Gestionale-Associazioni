<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Mansione/competenza del volontariato (f.2 del piano "Organizzazione 3"):
 * tassonomia per tenant assegnabile ai soci tramite il pivot socio_mansioni.
 */
class Mansione extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;

    protected $table = 'mansioni';

    protected $fillable = [
        'associazione_id',
        'nome',
        'descrizione',
    ];

    /**
     * Soci che possiedono questa mansione.
     *
     * @return BelongsToMany<Socio, $this>
     */
    public function soci(): BelongsToMany
    {
        return $this->belongsToMany(Socio::class, 'socio_mansioni', 'mansione_id', 'socio_id')
            ->withTimestamps();
    }
}
