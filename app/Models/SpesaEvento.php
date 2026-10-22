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
 * Spesa di un evento (f.17 del piano "Gestione 2"): voce di costo con
 * descrizione, importo e data, confrontata con budget e incassi delle
 * prenotazioni nella sezione economia del dettaglio evento.
 */
class SpesaEvento extends Model
{
    use NelPerimetroSedeTramite;

    /** Perimetro per sede tramite l'evento. */
    public const PERIMETRO_TRAMITE = 'evento';

    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;

    protected $table = 'spese_evento';

    protected $fillable = [
        'associazione_id',
        'evento_id',
        'descrizione',
        'importo',
        'data',
    ];

    protected function casts(): array
    {
        return [
            'importo' => 'decimal:2',
            'data' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Evento, $this>
     */
    public function evento(): BelongsTo
    {
        return $this->belongsTo(Evento::class, 'evento_id');
    }
}
