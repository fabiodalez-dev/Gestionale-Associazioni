<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Notifications\Accounts\ResetPasswordAccount;
use App\Notifications\Accounts\VerificaEmailAccount;
use App\Services\Privacy\ArchivioDatiPersonali;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\PersonalDataExport\ExportsPersonalData;
use Spatie\PersonalDataExport\PersonalDataSelection;

/**
 * Account ospite del frontend pubblico (guard 'account').
 *
 * Entità separata dai soci: un visitatore si registra sul mini-sito
 * dell'associazione, prenota eventi e — se l'admin lo collega/promuove —
 * può essere associato a un Socio (socio_id unique, mai automatico).
 */
class Account extends Authenticatable implements ExportsPersonalData, MustVerifyEmail
{
    use BelongsToAssociazione;
    use HasFactory;
    use HasUuids;
    use Notifiable;
    use SoftDeletes;

    protected $table = 'accounts';

    protected $fillable = [
        'associazione_id',
        'nome',
        'cognome',
        'email',
        'password',
        'email_verified_at',
        'telefono',
        'socio_id',
        'attivo',
        'preferenze',
        'privacy_accettata_at',
        'richiesta_socio_at',
        'ultimo_accesso_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'attivo' => 'boolean',
            'preferenze' => 'array',
            'privacy_accettata_at' => 'datetime',
            'richiesta_socio_at' => 'datetime',
            'ultimo_accesso_at' => 'datetime',
        ];
    }

    /**
     * Verifica email con link firmato tenant-aware
     * (SMTP del tenant se configurato, fallback al mailer di default).
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerificaEmailAccount);
    }

    /**
     * Reset password del broker 'accounts' con link verso il tenant.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordAccount($token));
    }

    public function getNomeCompletoAttribute(): string
    {
        return trim($this->nome.' '.$this->cognome);
    }

    public function isAttivo(): bool
    {
        return (bool) $this->attivo;
    }

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }

    /**
     * @return HasMany<Prenotazione, $this>
     */
    public function prenotazioni(): HasMany
    {
        return $this->hasMany(Prenotazione::class, 'account_id');
    }

    /**
     * Cancellazione GDPR: anonimizza i dati personali e disattiva l'account
     * (soft delete). Le prenotazioni restano intatte (FK restrictOnDelete,
     * contabilità non toccata): vengono scollegate solo dall'identità.
     */
    public function anonimizza(): void
    {
        $this->forceFill([
            'nome' => 'Account',
            'cognome' => 'Anonimizzato',
            // Mantiene unique(associazione_id, email): l'id è già univoco
            'email' => 'anonimizzato-'.$this->id.'@anonimo.invalid',
            'password' => bin2hex(random_bytes(32)),
            'email_verified_at' => null,
            'telefono' => null,
            'socio_id' => null,
            'attivo' => false,
            'preferenze' => null,
            'remember_token' => null,
        ])->save();

        $this->delete();
    }

    /** Contenuto dell'archivio dei dati personali (spatie/laravel-personal-data-export). */
    public function selectPersonalData(PersonalDataSelection $personalDataSelection): void
    {
        app(ArchivioDatiPersonali::class)->riempi($personalDataSelection, $this);
    }

    public function personalDataExportName(): string
    {
        return 'dati-personali.zip';
    }
}
