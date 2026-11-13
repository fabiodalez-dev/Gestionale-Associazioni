<?php

namespace App\Models;

use App\Models\Concerns\HasDefaultActivityLog;
use App\Services\ApiV1\ApiClientContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Chiavi API REST v1.
 *
 * Nota multi-tenancy: NON usa il global scope BelongsToAssociazione perché
 * le chiavi GLOBALI dei super_admin hanno associazione_id NULL (lo scope le
 * nasconderebbe e l'auto-fill ne impedirebbe la creazione). Una chiave è
 * globale solo con il flag esplicito `globale`: associazione_id NULL senza
 * flag è una chiave orfana e non autentica (audit run-2: prima una chiave
 * di un'associazione eliminata diventava globale per via della FK
 * nullOnDelete).
 *
 * Nota sicurezza: la chiave in chiaro NON viene mai persistita. In tabella
 * restano solo `api_key_hash` (sha256) e `api_key_prefix` (8 caratteri per
 * riconoscerla in UI). L'attributo virtuale `api_key` accetta la chiave in
 * chiaro alla creazione (calcola hash e prefisso) e la restituisce soltanto
 * finché il modello appena creato è in memoria: su un modello letto dal
 * database vale null.
 */
class ApiKey extends Model
{
    use HasDefaultActivityLog;
    use HasUuids;

    /** Lunghezza del prefisso mostrato in UI al posto della chiave. */
    public const LUNGHEZZA_PREFISSO = 8;

    protected $table = 'api_keys';

    protected $fillable = [
        'associazione_id',
        'globale',
        'api_key',
        'api_key_hash',
        'api_key_prefix',
        'nome',
        'permessi',
        'attiva',
        'ultimo_utilizzo',
        'scadenza',
    ];

    protected $hidden = [
        'api_key_hash',
    ];

    /** @var list<string> attributi esclusi dall'activity log */
    protected array $activityLogExcept = [
        'api_key_hash',
        'ultimo_utilizzo',
    ];

    /** Chiave in chiaro, disponibile solo in memoria subito dopo la creazione. */
    private ?string $chiaveInChiaro = null;

    protected function casts(): array
    {
        return [
            'permessi' => 'array',
            'attiva' => 'boolean',
            'globale' => 'boolean',
            'ultimo_utilizzo' => 'datetime',
            'scadenza' => 'date',
        ];
    }

    /**
     * Genera una nuova chiave in chiaro: 256 bit esadecimali (64 caratteri).
     */
    public static function generaChiave(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Hash persistito della chiave (sha256 esadecimale, 64 caratteri).
     */
    public static function hashChiave(string $chiave): string
    {
        return hash('sha256', $chiave);
    }

    public static function prefissoChiave(string $chiave): string
    {
        return substr($chiave, 0, self::LUNGHEZZA_PREFISSO);
    }

    /**
     * Mutator dell'attributo virtuale `api_key`: dalla chiave in chiaro
     * ricava hash e prefisso; il valore in chiaro resta solo in memoria.
     */
    public function setApiKeyAttribute(?string $chiave): void
    {
        $this->chiaveInChiaro = $chiave;
        $this->attributes['api_key_hash'] = $chiave === null ? null : self::hashChiave($chiave);
        $this->attributes['api_key_prefix'] = $chiave === null ? null : self::prefissoChiave($chiave);
    }

    /**
     * Accessor dell'attributo virtuale `api_key`: la chiave in chiaro solo
     * per il modello appena creato, altrimenti null (non è recuperabile).
     */
    public function getApiKeyAttribute(): ?string
    {
        return $this->chiaveInChiaro;
    }

    /**
     * @return BelongsTo<Associazione, $this>
     */
    public function associazione(): BelongsTo
    {
        return $this->belongsTo(Associazione::class, 'associazione_id');
    }

    /**
     * Globale = creata come tale da un super admin (flag esplicito) e non
     * vincolata ad alcuna associazione.
     */
    public function isGlobale(): bool
    {
        return (bool) $this->globale && $this->associazione_id === null;
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
        return ApiClientContext::permessoConcesso($this->permessi ?? [], $permesso);
    }

    public function scopeAttive(Builder $query): Builder
    {
        return $query->where('attiva', true);
    }

    /**
     * Lookup per chiave in chiaro: confronta l'hash, mai il valore.
     */
    public function scopePerChiave(Builder $query, string $chiave): Builder
    {
        return $query->where('api_key_hash', self::hashChiave($chiave));
    }
}
