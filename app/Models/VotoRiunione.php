<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Voto espresso su una votazione dell'assemblea (issue #8). Nel voto segreto
 * socio_id è null e la riga non ha date né log attività: insieme agli id
 * casuali (non ordinati nel tempo) impedisce di collegare il voto a chi
 * risulta in votanti_riunione.
 */
class VotoRiunione extends Model
{
    use BelongsToAssociazione;
    use HasUuids;

    public const FAVOREVOLE = 'favorevole';

    public const CONTRARIO = 'contrario';

    public const ASTENUTO = 'astenuto';

    public const OPZIONI = [
        self::FAVOREVOLE => 'Favorevole',
        self::CONTRARIO => 'Contrario',
        self::ASTENUTO => 'Astenuto',
    ];

    public $timestamps = false;

    protected $table = 'voti_riunione';

    protected $fillable = [
        'associazione_id',
        'votazione_id',
        'opzione',
        'socio_id',
    ];

    /**
     * UUID casuale (v4), non ordinato nel tempo: l'ordine degli id non deve
     * rivelare la sequenza dei voti.
     */
    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }
}
