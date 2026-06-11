<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampoPersonalizzato extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasUuids;

    public const TIPI_CAMPO = [
        'text', 'email', 'tel', 'number', 'date', 'url', 'textarea', 'select', 'checkbox',
    ];

    protected $table = 'campi_personalizzati';

    protected $fillable = [
        'associazione_id',
        'nome_campo',
        'tipo_campo',
        'descrizione',
        'opzioni',
        'obbligatorio',
        'obbligatorio_preiscrizione',
        'ordine',
    ];

    protected function casts(): array
    {
        return [
            'obbligatorio' => 'boolean',
            'obbligatorio_preiscrizione' => 'boolean',
            'ordine' => 'integer',
        ];
    }

    public function valori(): HasMany
    {
        return $this->hasMany(ValoreCampoPersonalizzato::class, 'campo_id');
    }
}
