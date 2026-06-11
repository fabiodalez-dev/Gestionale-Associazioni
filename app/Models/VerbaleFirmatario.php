<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Firmatario strutturato di un verbale (estensione §6.2 della spec):
 * può essere un socio, un utente admin oppure un nominativo libero,
 * con ruolo nella riunione (Presidente, Segretario, ...) e data firma.
 */
class VerbaleFirmatario extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasUuids;

    protected $table = 'verbale_firmatari';

    protected $fillable = [
        'verbale_id',
        'associazione_id',
        'socio_id',
        'utente_id',
        'nome',
        'ruolo',
        'firmato_at',
    ];

    protected function casts(): array
    {
        return [
            'firmato_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Verbale, $this>
     */
    public function verbale(): BelongsTo
    {
        return $this->belongsTo(Verbale::class, 'verbale_id');
    }

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }

    /**
     * @return BelongsTo<Utente, $this>
     */
    public function utente(): BelongsTo
    {
        return $this->belongsTo(Utente::class, 'utente_id');
    }

    /**
     * Nominativo da mostrare: socio > utente > nome libero.
     */
    public function getNominativoAttribute(): string
    {
        if ($this->socio) {
            return trim($this->socio->nome.' '.$this->socio->cognome);
        }

        if ($this->utente) {
            return $this->utente->nome_completo;
        }

        return (string) $this->nome;
    }
}
