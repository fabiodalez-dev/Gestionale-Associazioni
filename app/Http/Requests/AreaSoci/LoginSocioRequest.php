<?php

namespace App\Http\Requests\AreaSoci;

use App\Models\Socio;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginSocioRequest extends FormRequest
{
    /** Tentativi massimi per finestra di un minuto (miglioramento: il legacy non ha throttling). */
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
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'email' => 'email',
            'password' => 'password',
        ];
    }

    /**
     * Autentica il socio replicando il flusso legacy:
     * lookup globale per email (cross-tenant) con password_hash valorizzato,
     * errore generico unico (nessuna user-enumeration), rate limit per email+IP.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        /** @var Socio|null $socio */
        $socio = Socio::query()
            ->withoutGlobalScope('associazione')
            ->whereNotNull('password_hash')
            ->where('email', $this->string('email')->toString())
            ->first();

        if ($socio === null || ! Hash::check($this->string('password')->toString(), (string) $socio->password_hash)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => 'Credenziali non valide.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        Auth::guard('socio')->login($socio);
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
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
        return 'area-soci-login|'.Str::transliterate(Str::lower($this->string('email')->toString()).'|'.$this->ip());
    }
}
