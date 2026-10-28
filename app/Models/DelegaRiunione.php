<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Delega per l'assemblea dei soci (issue #7): il delegante, avente diritto
 * che non partecipa, si fa rappresentare dal delegato. La delega conta per
 * il quorum e per il voto solo se il delegato è presente e il delegante no:
 * chi si presenta di persona riprende il proprio voto.
 */
class DelegaRiunione extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasUuids;

    public const ORIGINE_AREA_SOCI = 'area_soci';

    public const ORIGINE_SEGRETERIA = 'segreteria';

    protected $table = 'deleghe_riunione';

    protected $fillable = [
        'associazione_id',
        'riunione_id',
        'delegante_socio_id',
        'delegato_socio_id',
        'origine',
        'registrata_da',
    ];

    /**
     * @return BelongsTo<Riunione, $this>
     */
    public function riunione(): BelongsTo
    {
        return $this->belongsTo(Riunione::class, 'riunione_id');
    }

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function delegante(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'delegante_socio_id');
    }

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function delegato(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'delegato_socio_id');
    }

    /**
     * @return BelongsTo<Utente, $this>
     */
    public function registrataDa(): BelongsTo
    {
        return $this->belongsTo(Utente::class, 'registrata_da');
    }
}
