<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Iscrizione di un ospite in attesa della conferma dell'email
 * (FormIscrizioneService::preparaConfermaOspite / confermaOspite).
 *
 * Scope di tenant come ogni tabella con associazione_id (nel flusso pubblico
 * il tenant arriva dallo slug); le query del servizio filtrano comunque
 * esplicitamente per associazione_id. dati_form è lo snapshot validato del
 * modulo, cifrato; scaduta (scade_at) la riga non vale più ed è eliminabile.
 *
 * @property string $id
 * @property string $associazione_id
 * @property string $email
 * @property array<string, mixed> $dati_form
 * @property ?string $ip
 * @property ?string $referral_codice
 * @property Carbon $scade_at
 */
class IscrizioneDaConfermare extends Model
{
    use BelongsToAssociazione;
    use HasUuids;
    use Prunable;

    protected $table = 'iscrizioni_da_confermare';

    protected $fillable = [
        'associazione_id',
        'email',
        'dati_form',
        'ip',
        'referral_codice',
        'scade_at',
    ];

    protected function casts(): array
    {
        return [
            'dati_form' => 'encrypted:array',
            'scade_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Associazione, $this>
     */
    public function associazione(): BelongsTo
    {
        return $this->belongsTo(Associazione::class);
    }

    public function isScaduta(): bool
    {
        return $this->scade_at->isPast();
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->withoutGlobalScopes()->where('scade_at', '<', now());
    }
}
