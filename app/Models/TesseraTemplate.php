<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TesseraTemplate extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasUuids;

    protected $table = 'tessera_templates';

    protected $fillable = [
        'associazione_id',
        'tipo_socio_id',
        'titolo',
        'contenuto',
        'attivo',
    ];

    protected function casts(): array
    {
        return [
            'attivo' => 'boolean',
        ];
    }

    public function tipoSocio(): BelongsTo
    {
        return $this->belongsTo(TipoSocio::class, 'tipo_socio_id');
    }
}
