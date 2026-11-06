<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scheda del socio nella rubrica interna (issue #14): visibile agli altri
 * soci solo se attiva, con i soli campi scelti dal socio.
 */
class RubricaScheda extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasUuids;

    /** Campi che il socio può decidere di mostrare (nome e cognome si vedono sempre). */
    public const CAMPI = [
        'email' => 'Email',
        'telefono' => 'Telefono',
        'citta' => 'Città',
        'sede' => 'Sede',
        'presentazione' => 'Presentazione',
        'interessi' => 'Interessi e competenze',
    ];

    protected $table = 'rubrica_schede';

    protected $attributes = [
        'attiva' => false,
    ];

    protected $fillable = [
        'associazione_id',
        'socio_id',
        'attiva',
        'campi_visibili',
        'presentazione',
        'interessi',
        'consenso_at',
        'revocata_at',
    ];

    protected function casts(): array
    {
        return [
            'attiva' => 'boolean',
            'campi_visibili' => 'array',
            'interessi' => 'array',
            'consenso_at' => 'datetime',
            'revocata_at' => 'datetime',
        ];
    }

    public function mostra(string $campo): bool
    {
        return in_array($campo, (array) ($this->campi_visibili ?? []), true);
    }

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }
}
