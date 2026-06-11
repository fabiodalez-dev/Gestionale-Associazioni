<?php

namespace App\Models;

use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Valore EAV di un campo personalizzato per un socio.
 * Nessuna colonna associazione_id: il tenant è derivato dal socio/campo.
 */
class ValoreCampoPersonalizzato extends Model
{
    use HasDefaultActivityLog;
    use HasUuids;

    protected $table = 'valori_campi_personalizzati';

    protected $fillable = [
        'socio_id',
        'campo_id',
        'valore',
    ];

    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }

    public function campo(): BelongsTo
    {
        return $this->belongsTo(CampoPersonalizzato::class, 'campo_id');
    }
}
