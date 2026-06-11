<?php

namespace App\Models;

use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Account amministrativi (tabella legacy `utenti`).
 *
 * Nota multi-tenancy: questo modello NON usa il global scope BelongsToAssociazione
 * perché è la tabella di autenticazione (i super_admin hanno associazione_id NULL
 * e il lookup di login non deve mai essere filtrato per tenant).
 */
class Utente extends Authenticatable
{
    use HasApiTokens;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasRoles;
    use HasUuids;
    use Notifiable;
    use TwoFactorAuthenticatable;

    public const RUOLO_SUPER_ADMIN = 'super_admin';

    public const RUOLO_ADMIN_ASSOCIAZIONE = 'admin_associazione';

    public const RUOLO_OPERATORE = 'operatore';

    public const RUOLI = [
        self::RUOLO_SUPER_ADMIN,
        self::RUOLO_ADMIN_ASSOCIAZIONE,
        self::RUOLO_OPERATORE,
    ];

    protected $table = 'utenti';

    protected $fillable = [
        'associazione_id',
        'nome',
        'cognome',
        'email',
        'password_hash',
        'ruolo',
        'attivo',
        'last_login',
    ];

    protected $hidden = [
        'password_hash',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /** @var list<string> attributi esclusi dall'activity log */
    protected array $activityLogExcept = [
        'password_hash',
        'last_login',
    ];

    protected function casts(): array
    {
        return [
            'attivo' => 'boolean',
            'last_login' => 'datetime',
            'password_hash' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * La colonna password legacy si chiama password_hash.
     */
    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    public function isSuperAdmin(): bool
    {
        return $this->ruolo === self::RUOLO_SUPER_ADMIN;
    }

    public function isAdminAssociazione(): bool
    {
        return $this->ruolo === self::RUOLO_ADMIN_ASSOCIAZIONE;
    }

    public function isOperatore(): bool
    {
        return $this->ruolo === self::RUOLO_OPERATORE;
    }

    public function getNomeCompletoAttribute(): string
    {
        return trim($this->nome.' '.$this->cognome);
    }

    public function associazione(): BelongsTo
    {
        return $this->belongsTo(Associazione::class, 'associazione_id');
    }

    public function savedFilters(): HasMany
    {
        return $this->hasMany(SavedFilter::class, 'user_id');
    }

    public function documentiCaricati(): HasMany
    {
        return $this->hasMany(Documento::class, 'caricato_da');
    }

    public function scadenzeAssegnate(): HasMany
    {
        return $this->hasMany(Scadenza::class, 'assegnato_a');
    }

    public function verbaliApprovati(): HasMany
    {
        return $this->hasMany(Verbale::class, 'approvato_da');
    }
}
