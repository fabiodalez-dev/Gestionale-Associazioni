<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Scadenza extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasUuids;

    public const TIPI = ['Quote', 'Documenti', 'Eventi', 'Generale'];

    public const PRIORITA = ['Bassa', 'Media', 'Alta', 'Critica'];

    public const STATI = ['Attiva', 'Completata', 'Annullata'];

    public const STATO_ATTIVA = 'Attiva';

    public const STATO_COMPLETATA = 'Completata';

    public const STATO_ANNULLATA = 'Annullata';

    protected $table = 'scadenze';

    protected $fillable = [
        'associazione_id',
        'titolo',
        'descrizione',
        'data_scadenza',
        'tipo_scadenza',
        'priorita',
        'stato',
        'assegnato_a',
        'promemoria_giorni',
        'completata_da',
        'completata_at',
        'promemoria_inviato_at',
    ];

    protected function casts(): array
    {
        return [
            'data_scadenza' => 'date',
            'promemoria_giorni' => 'integer',
            'completata_at' => 'datetime',
            'promemoria_inviato_at' => 'datetime',
        ];
    }

    public function assegnatoA(): BelongsTo
    {
        return $this->belongsTo(Utente::class, 'assegnato_a');
    }

    public function completataDa(): BelongsTo
    {
        return $this->belongsTo(Utente::class, 'completata_da');
    }

    /**
     * Giorni residui alla scadenza: negativo se scaduta, 0 se scade oggi.
     * Confronto tra date pure (Carbon::today), come consigliato dalla spec §3.4.
     */
    public function getGiorniResiduiAttribute(): int
    {
        return (int) Carbon::today()->diffInDays($this->data_scadenza->startOfDay(), false);
    }

    public function isScaduta(): bool
    {
        return $this->giorni_residui < 0;
    }

    /**
     * In finestra promemoria: usa il campo per-record promemoria_giorni.
     */
    public function isInScadenza(): bool
    {
        return $this->giorni_residui >= 0 && $this->giorni_residui <= $this->promemoria_giorni;
    }

    public function isAttiva(): bool
    {
        return $this->stato === self::STATO_ATTIVA;
    }
}
