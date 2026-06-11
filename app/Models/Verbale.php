<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Verbale extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasUuids;
    use SoftDeletes;

    public const TIPI_RIUNIONE = [
        'Assemblea Ordinaria',
        'Assemblea Straordinaria',
        'Consiglio Direttivo',
        'Commissione',
    ];

    protected $table = 'verbali';

    protected $fillable = [
        'associazione_id',
        'titolo',
        'data_riunione',
        'tipo_riunione',
        'presenti',
        'ordine_del_giorno',
        'deliberazioni',
        'allegati',
        'approvato',
        'approvato_da',
        'data_approvazione',
    ];

    protected function casts(): array
    {
        return [
            'data_riunione' => 'date',
            'approvato' => 'boolean',
            'data_approvazione' => 'date',
        ];
    }

    public function approvatoDa(): BelongsTo
    {
        return $this->belongsTo(Utente::class, 'approvato_da');
    }

    public function firmatari(): HasMany
    {
        return $this->hasMany(VerbaleFirmatario::class, 'verbale_id');
    }

    /**
     * Azzera lo stato di approvazione (usato alla modifica di un verbale
     * approvato: irrigidimento consigliato dalla spec §6).
     */
    public function azzeraApprovazione(): void
    {
        $this->approvato = false;
        $this->approvato_da = null;
        $this->data_approvazione = null;
    }
}
