<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use App\Support\PerimetroSedi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sede extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasUuids;

    protected $table = 'sedi';

    protected $fillable = [
        'associazione_id',
        'nome',
        'indirizzo',
        'citta',
        'provincia',
        'cap',
        'email',
        'telefono',
        'responsabile',
    ];

    /**
     * @return HasMany<Socio, $this>
     */
    /**
     * Utente limitato a delle sedi (PerimetroSedi): vede solo le sue, quindi
     * anche i menu a tendina dei form mostrano solo quelle.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('sede', function (Builder $builder): void {
            $sedi = PerimetroSedi::sedi();
            if ($sedi !== null) {
                $builder->whereIn($builder->qualifyColumn('id'), $sedi);
            }
        });
    }

    /**
     * @return BelongsToMany<Utente, $this>
     */
    public function utenti(): BelongsToMany
    {
        return $this->belongsToMany(Utente::class, 'utente_sedi', 'sede_id', 'utente_id')->withTimestamps();
    }

    public function soci(): HasMany
    {
        return $this->hasMany(Socio::class, 'sede_id');
    }
}
