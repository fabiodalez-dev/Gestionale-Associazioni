<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use App\Models\Concerns\NelPerimetroSedeTramite;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cessazione formale del rapporto associativo (f.15 del piano):
 * registro delle dimissioni/decadenze/espulsioni/decessi con data,
 * motivo ed eventuale delibera. Alimenta il libro soci (f.13).
 */
class Cessazione extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;
    use NelPerimetroSedeTramite;

    public const TIPO_DIMISSIONI = 'dimissioni';

    public const TIPO_DECADENZA = 'decadenza';

    public const TIPO_ESPULSIONE = 'espulsione';

    public const TIPO_DECESSO = 'decesso';

    public const TIPI = [
        self::TIPO_DIMISSIONI,
        self::TIPO_DECADENZA,
        self::TIPO_ESPULSIONE,
        self::TIPO_DECESSO,
    ];

    /**
     * Stato Socio coerente per ciascun tipo di cessazione (f.15:
     * "aggiorna stato socio coerente").
     */
    public const STATO_SOCIO_PER_TIPO = [
        self::TIPO_DIMISSIONI => Socio::STATO_RADIATO,
        self::TIPO_DECADENZA => Socio::STATO_RADIATO,
        self::TIPO_ESPULSIONE => Socio::STATO_RADIATO,
        self::TIPO_DECESSO => Socio::STATO_DECEDUTO,
    ];

    protected $table = 'cessazioni';

    protected $fillable = [
        'associazione_id',
        'socio_id',
        'tipo',
        'data',
        'motivo',
        'delibera_numero',
        'delibera_data',
        'registrata_da',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'date',
            'delibera_data' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }

    /**
     * Admin che ha registrato la cessazione.
     *
     * @return BelongsTo<Utente, $this>
     */
    public function registrataDa(): BelongsTo
    {
        return $this->belongsTo(Utente::class, 'registrata_da');
    }
}
