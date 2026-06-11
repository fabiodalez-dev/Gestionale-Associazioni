<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Evento extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'eventi';

    protected $fillable = [
        'associazione_id',
        'titolo',
        'descrizione',
        'data_evento',
        'luogo',
    ];

    protected function casts(): array
    {
        return [
            'data_evento' => 'datetime',
        ];
    }

    /**
     * @return HasMany<EventoPartecipante, $this>
     */
    public function partecipanti(): HasMany
    {
        return $this->hasMany(EventoPartecipante::class, 'evento_id');
    }

    /**
     * @return BelongsToMany<Socio, $this>
     */
    public function soci(): BelongsToMany
    {
        return $this->belongsToMany(Socio::class, 'eventi_partecipanti', 'evento_id', 'socio_id')
            ->withPivot(['stato_partecipazione', 'data_conferma', 'note']);
    }

    /**
     * @return HasMany<Tessera, $this>
     */
    public function tessereCreate(): HasMany
    {
        return $this->hasMany(Tessera::class, 'evento_creazione_id');
    }
}
