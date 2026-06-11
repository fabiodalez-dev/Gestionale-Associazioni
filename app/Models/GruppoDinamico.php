<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class GruppoDinamico extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasUuids;

    protected $table = 'gruppi_dinamici';

    protected $fillable = [
        'associazione_id',
        'nome_gruppo',
        'descrizione',
        'filtri_json',
    ];

    protected function casts(): array
    {
        return [
            'filtri_json' => 'array',
        ];
    }
}
