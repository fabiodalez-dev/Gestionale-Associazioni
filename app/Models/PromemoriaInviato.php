<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracking dei promemoria inviati (anti-duplicazione, f.3/f.6/f.20/f.23 del
 * piano): un record per (socio, tessera, riferimento, tipo, step) — unique a
 * livello DB.
 *
 * step: significato dipendente dal tipo — giorni prima della scadenza
 * (rinnovo/documento, es. 30/15/3), anno (adempimento), o anno tessera.
 * tessera_id NULL per i promemoria non legati a una tessera (es. documenti).
 * riferimento_id: id dell'entità di riferimento quando la deduplica deve
 * distinguere fra entità diverse (turno_id, adempimento_id); NULL altrimenti.
 * socio_id NULL per i promemoria non legati a un socio (es. adempimenti agli
 * admin del tenant).
 *
 * NB: con tessera_id/riferimento_id NULL l'unique a DB non scatta (NULL
 * distinti in MySQL/SQLite): per quei promemoria la deduplicazione va fatta
 * applicativamente (whereNull(...) prima dell'insert).
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

    /** Promemoria turno in programma domani (f.6). */
    public const TIPO_TURNO = 'turno';

    /** Promemoria mensile di un adempimento ricorrente (f.20). */
    public const TIPO_ADEMPIMENTO = 'adempimento';

    protected $table = 'promemoria_inviati';

    protected $fillable = [
        'associazione_id',
        'socio_id',
        'tessera_id',
        'riferimento_id',
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
