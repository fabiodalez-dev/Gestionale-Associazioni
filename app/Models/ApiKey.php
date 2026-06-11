<?php

namespace App\Models;

use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Chiavi API REST v1.
 *
 * Nota multi-tenancy: NON usa il global scope BelongsToAssociazione perché
 * associazione_id NULL identifica le chiavi GLOBALI dei super_admin
 * (lo scope le nasconderebbe e l'auto-fill ne impedirebbe la creazione).
 */
class ApiKey extends Model
{
    use HasDefaultActivityLog;
    use HasUuids;

    protected $table = 'api_keys';

    protected $fillable = [
        'associazione_id',
        'api_key',
        'nome',
        'permessi',
        'attiva',
        'ultimo_utilizzo',
        'scadenza',
    ];

    protected $hidden = [
        'api_key',
    ];

    /** @var list<string> attributi esclusi dall'activity log */
    protected array $activityLogExcept = [
        'api_key',
        'ultimo_utilizzo',
    ];

    protected function casts(): array
    {
        return [
            'permessi' => 'array',
            'attiva' => 'boolean',
            'ultimo_utilizzo' => 'datetime',
            'scadenza' => 'date',
        ];
    }

    public function associazione(): BelongsTo
    {
        return $this->belongsTo(Associazione::class, 'associazione_id');
    }

    public function isGlobale(): bool
    {
        return $this->associazione_id === null;
    }

    public function isValida(): bool
    {
        return $this->attiva
            && ($this->scadenza === null || $this->scadenza->gte(now()->startOfDay()));
    }

    /**
     * Verifica un permesso con supporto wildcard (es. 'eventi:write', 'soci:*', '*').
     */
    public function hasPermesso(string $permesso): bool
    {
        $permessi = $this->permessi ?? [];

        if (in_array('*', $permessi, true) || in_array($permesso, $permessi, true)) {
            return true;
        }

        $modulo = explode(':', $permesso)[0];

        return in_array($modulo.':*', $permessi, true);
    }

    public function scopeAttive(Builder $query): Builder
    {
        return $query->where('attiva', true);
    }
}
