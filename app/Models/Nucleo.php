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
 * Nucleo familiare (f.20 del piano "Gestione 2"): raggruppa più soci
 * con un capofamiglia; i membri sono i soci con soci.nucleo_id valorizzato.
 */
class Nucleo extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;

    protected $table = 'nuclei';

    protected $fillable = [
        'associazione_id',
        'nome',
        'capofamiglia_id',
    ];

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function capofamiglia(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'capofamiglia_id');
    }

    /**
     * @return HasMany<Socio, $this>
     */
    public function membri(): HasMany
    {
        return $this->hasMany(Socio::class, 'nucleo_id');
    }
}
