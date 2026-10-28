<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Scheda elettorale online (issue #9): solo le preferenze, senza socio, date
 * o log attività, con id casuale non ordinato nel tempo: non si collega a
 * chi risulta in votanti_elezione. Preferenze vuote = scheda bianca.
 */
class SchedaElezione extends Model
{
    use BelongsToAssociazione;
    use HasUuids;

    public $timestamps = false;

    protected $table = 'schede_elezione';

    protected $fillable = [
        'associazione_id',
        'elezione_id',
        'preferenze',
    ];

    protected function casts(): array
    {
        return [
            'preferenze' => 'array',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }
}
