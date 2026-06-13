<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Attrezzatura dell'inventario (f.9 del piano "Organizzazione 3"):
 * quantità, stato di conservazione e ubicazione; la disponibilità per i
 * prestiti (f.10) è calcolata come quantita - prestiti attivi.
 */
class Attrezzatura extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;

    public const STATO_BUONO = 'buono';

    public const STATO_DA_RIPARARE = 'da_riparare';

    public const STATO_FUORI_USO = 'fuori_uso';

    public const STATI = [
        self::STATO_BUONO,
        self::STATO_DA_RIPARARE,
        self::STATO_FUORI_USO,
    ];

    protected $table = 'attrezzature';

    protected $fillable = [
        'associazione_id',
        'nome',
        'descrizione',
        'quantita',
        'valore_unitario',
        'stato',
        'spazio_id',
        'ubicazione',
    ];

    protected function casts(): array
    {
        return [
            'quantita' => 'integer',
            'valore_unitario' => 'decimal:2',
        ];
    }

    /**
     * Quantità in prestito non ancora restituita (f.10).
     */
    public function quantitaInPrestito(): int
    {
        return (int) $this->prestiti()->attivi()->sum('quantita');
    }

    /**
     * Quantità disponibile per nuovi prestiti: quantita - prestiti attivi.
     */
    public function quantitaDisponibile(): int
    {
        return max(0, (int) $this->quantita - $this->quantitaInPrestito());
    }

    /**
     * @return HasMany<PrestitoAttrezzatura, $this>
     */
    public function prestiti(): HasMany
    {
        return $this->hasMany(PrestitoAttrezzatura::class, 'attrezzatura_id');
    }

    /**
     * @return BelongsTo<Spazio, $this>
     */
    public function spazio(): BelongsTo
    {
        return $this->belongsTo(Spazio::class, 'spazio_id');
    }
}
