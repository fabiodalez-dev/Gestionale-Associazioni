<?php

namespace App\Http\Requests\FrontendPubblico;

use App\Support\LimiteAccessi;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Login account sul frontend pubblico (pattern LoginSocioRequest).
 *
 * Il lookup è TENANT-SCOPED: il global scope di BelongsToAssociazione
 * (binding app('tenant.pubblico') impostato da RisolviTenantPubblico)
 * limita Auth::guard('account')->attempt() agli account del tenant
 * corrente — un account di A non entra mai su /a/slug-b.
 * Errore generico unico (niente user-enumeration), rate limit email+IP.
 */
class LoginAccountRequest extends FormRequest
{
    /** Tentativi massimi per email+IP nella finestra di un minuto. */
    public const MAX_TENTATIVI = 5;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $credenziali = [
            'email' => $this->string('email')->toString(),
            'password' => $this->string('password')->toString(),
            // Account disattivati dall'admin: stesse credenziali rifiutate
            'attivo' => true,
        ];

        if (! Auth::guard('account')->attempt($credenziali, $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey(), LimiteAccessi::secondiBlocco());

            throw ValidationException::withMessages([
                'email' => 'Credenziali non valide.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_TENTATIVI)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => "Troppi tentativi di accesso. Riprova tra {$seconds} secondi.",
        ]);
    }

    public function throttleKey(): string
    {
        return LimiteAccessi::chiave('pubblico-login|'.Str::transliterate(
            Str::lower($this->string('email')->toString()).'|'.$this->ip()
        ));
    }
}
