<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracking dei promemoria inviati (anti-duplicazione, f.3 e f.23 del piano):
 * un record per (socio, tessera, tipo, step) — unique a livello DB.
 *
 * step = giorni prima della scadenza (es. 30, 15, 3).
 * tessera_id NULL per i promemoria non legati a una tessera (es. documenti).
 *
 * NB: con tessera_id NULL l'unique a DB non scatta (NULL distinti in
 * MySQL/SQLite): per quei promemoria la deduplicazione va fatta
 * applicativamente (whereNull('tessera_id') prima dell'insert).
 */
class PromemoriaInviato extends Model
{
    use BelongsToAssociazione;
    use HasFactory;
    use HasUuids;

    /** Promemoria rinnovo tessera in scadenza (f.3). */
    public const TIPO_RINNOVO_TESSERA = 'rinnovo_tessera';

    /** Promemoria documento socio in scadenza (f.23). */
    public const TIPO_DOCUMENTO_SCADENZA = 'documento_scadenza';

    public const TIPI = [
        self::TIPO_RINNOVO_TESSERA,
        self::TIPO_DOCUMENTO_SCADENZA,
    ];

    protected $table = 'promemoria_inviati';

    protected $fillable = [
        'associazione_id',
        'socio_id',
        'tessera_id',
        'tipo',
        'step',
        'inviato_at',
    ];

    protected function casts(): array
    {
        return [
            'step' => 'integer',
            'inviato_at' => 'datetime',
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
     * @return BelongsTo<Tessera, $this>
     */
    public function tessera(): BelongsTo
    {
        return $this->belongsTo(Tessera::class, 'tessera_id');
    }
}
