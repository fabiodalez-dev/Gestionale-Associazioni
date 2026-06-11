<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TipoSocio extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasUuids;

    protected $table = 'tipi_socio';

    protected $fillable = [
        'associazione_id',
        'nome',
        'descrizione',
        'costo_tessera',
    ];

    protected function casts(): array
    {
        return [
            'costo_tessera' => 'decimal:2',
        ];
    }

    public function soci(): HasMany
    {
        return $this->hasMany(Socio::class, 'tipo_socio_id');
    }

    public function tesseraTemplates(): HasMany
    {
        return $this->hasMany(TesseraTemplate::class, 'tipo_socio_id');
    }

    /**
     * Costo tessera effettivo: override del tipo con fallback sull'associazione.
     */
    public function costoTesseraEffettivo(): ?string
    {
        return $this->costo_tessera ?? $this->associazione?->costo_tessera;
    }
}
