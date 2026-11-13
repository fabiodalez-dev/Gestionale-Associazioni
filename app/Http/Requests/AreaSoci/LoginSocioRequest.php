<?php

namespace App\Http\Requests\AreaSoci;

use App\Models\Socio;
use App\Support\LimiteAccessi;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Database\Eloquent\Collection;
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

    /**
     * Chiave di sessione della scelta dell'associazione: la stessa email può
     * essere socia di più associazioni con la stessa password; dopo la
     * verifica la sessione conserva solo gli id dei soci per cui la password
     * è risultata corretta, con una scadenza breve.
     */
    public const SESSIONE_SCELTA = 'area_soci.scelta_associazione';

    /** Validità della scelta in sospeso (secondi). */
    public const SCELTA_TTL = 300;

    /**
     * Tetto ai soci verificati per un'email: ogni verifica costa un hash
     * bcrypt, e nessuna persona reale è socia di decine di associazioni.
     */
    public const MAX_CANDIDATI = 20;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        if ($this->annullaScelta()) {
            return [];
        }

        if ($this->isScelta()) {
            return ['socio_scelto' => ['required', 'string', 'uuid']];
        }

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
            'socio_scelto' => 'associazione',
        ];
    }

    /** Secondo passo: il socio sceglie l'associazione tra quelle proposte. */
    public function isScelta(): bool
    {
        return $this->has('socio_scelto');
    }

    /** Il socio rinuncia alla scelta e torna al form di login. */
    public function annullaScelta(): bool
    {
        return $this->boolean('annulla_scelta');
    }

    /**
     * Autentica il socio con il flusso legacy (lookup globale per email,
     * cross-tenant, password_hash valorizzato, errore generico unico, rate
     * limit per email+IP), esteso alle email presenti in più associazioni:
     * la password viene verificata su tutti i soci con quell'email e accesso
     * all'area soci. Se combacia con uno solo si entra lì; se combacia con
     * più di uno la sessione conserva gli id e la pagina di login propone la
     * scelta (i nomi delle associazioni si vedono solo dopo la password giusta).
     *
     * @return bool true se il socio è autenticato, false se deve scegliere l'associazione
     *
     * @throws ValidationException
     */
    public function authenticate(): bool
    {
        $this->ensureIsNotRateLimited();

        $password = $this->string('password')->toString();

        /** @var Collection<int, Socio> $candidati */
        $candidati = Socio::query()
            ->withoutGlobalScope('associazione')
            ->whereNotNull('password_hash')
            ->whereIn('stato', Socio::STATI_CON_ACCESSO_AREA_SOCI)
            ->where('email', $this->string('email')->toString())
            ->orderBy('id')
            ->limit(self::MAX_CANDIDATI)
            ->get();

        $validi = $candidati
            ->filter(fn (Socio $socio) => Hash::check($password, (string) $socio->password_hash))
            ->values();

        if ($validi->isEmpty()) {
            RateLimiter::hit($this->throttleKey(), LimiteAccessi::secondiBlocco());

            throw ValidationException::withMessages([
                'email' => 'Credenziali non valide.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        if ($validi->count() === 1) {
            $this->session()->forget(self::SESSIONE_SCELTA);
            Auth::guard('socio')->login($validi->first());

            return true;
        }

        $this->session()->put(self::SESSIONE_SCELTA, [
            'ids' => $validi->pluck('id')->map(fn ($id) => (string) $id)->all(),
            'scade' => now()->addSeconds(self::SCELTA_TTL)->getTimestamp(),
        ]);

        return false;
    }

    /**
     * Secondo passo: entra nel socio scelto, solo se è tra quelli per cui la
     * password è già stata verificata in questa sessione e ha ancora accesso.
     *
     * @throws ValidationException
     */
    public function completaScelta(): void
    {
        $ids = self::idsInSospeso($this->session()->get(self::SESSIONE_SCELTA));
        $id = $this->string('socio_scelto')->toString();

        if ($ids === [] || ! in_array($id, $ids, true)) {
            $this->session()->forget(self::SESSIONE_SCELTA);

            throw ValidationException::withMessages([
                'email' => 'La scelta dell\'associazione non è più valida: accedi di nuovo.',
            ]);
        }

        /** @var Socio|null $socio */
        $socio = Socio::allTenants()
            ->whereNotNull('password_hash')
            ->whereIn('stato', Socio::STATI_CON_ACCESSO_AREA_SOCI)
            ->whereKey($id)
            ->first();

        $this->session()->forget(self::SESSIONE_SCELTA);

        if ($socio === null) {
            throw ValidationException::withMessages([
                'email' => 'La scelta dell\'associazione non è più valida: accedi di nuovo.',
            ]);
        }

        Auth::guard('socio')->login($socio);
    }

    /**
     * Id dei soci della scelta in sospeso, se presente e non scaduta.
     *
     * @return list<string>
     */
    public static function idsInSospeso(mixed $scelta): array
    {
        if (! is_array($scelta) || ! isset($scelta['ids'], $scelta['scade']) || ! is_array($scelta['ids'])) {
            return [];
        }

        if ((int) $scelta['scade'] < now()->getTimestamp()) {
            return [];
        }

        return array_values(array_map(fn ($id) => (string) $id, $scelta['ids']));
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
        return LimiteAccessi::chiave('area-soci-login|'.Str::transliterate(Str::lower($this->string('email')->toString()).'|'.$this->ip()));
    }
}
