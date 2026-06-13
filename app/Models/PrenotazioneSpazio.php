<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prenotazione interna di uno spazio (f.8 del piano "Organizzazione 3"):
 * intervallo [dal, al] con rilevazione conflitti di overlap sullo stesso
 * spazio (scopeInConflittoCon).
 */
class PrenotazioneSpazio extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;

    protected $table = 'prenotazioni_spazi';

    protected $fillable = [
        'associazione_id',
        'spazio_id',
        'titolo',
        'dal',
        'al',
        'richiedente_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'dal' => 'datetime',
            'al' => 'datetime',
        ];
    }

    /**
     * Prenotazioni dello stesso spazio che si sovrappongono all'intervallo
     * indicato (overlap aperto: dal < al_altro AND al > dal_altro, f.8).
     *
     * @param  Builder<PrenotazioneSpazio>  $query
     * @return Builder<PrenotazioneSpazio>
     */
    public function scopeInConflittoCon(
        Builder $query,
        string $spazioId,
        \DateTimeInterface|string $dal,
        \DateTimeInterface|string $al,
        ?string $ignoraId = null
    ): Builder {
        return $query->where('spazio_id', $spazioId)
            ->where('dal', '<', $al)
            ->where('al', '>', $dal)
            ->when($ignoraId !== null, fn (Builder $q) => $q->whereKeyNot($ignoraId));
    }

    /**
     * @return BelongsTo<Spazio, $this>
     */
    public function spazio(): BelongsTo
    {
        return $this->belongsTo(Spazio::class, 'spazio_id');
    }

    /**
     * Utente admin che ha richiesto la prenotazione.
     *
     * @return BelongsTo<Utente, $this>
     */
    public function richiedente(): BelongsTo
    {
        return $this->belongsTo(Utente::class, 'richiedente_id');
    }
}
