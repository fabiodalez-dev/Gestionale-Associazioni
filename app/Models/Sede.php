<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
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

    public function soci(): HasMany
    {
        return $this->hasMany(Socio::class, 'sede_id');
    }
}
