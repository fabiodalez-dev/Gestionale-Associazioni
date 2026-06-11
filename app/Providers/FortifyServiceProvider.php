<?php

namespace App\Providers;

use App\Actions\Fortify\UpdateUserPassword;
use App\Models\Utente;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\LogoutResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Dopo il login (anche con 2FA) si atterra sulla dashboard
        $this->app->singleton(LoginResponse::class, fn () => new class implements LoginResponse
        {
            public function toResponse($request)
            {
                return redirect()->intended(route('dashboard'));
            }
        });

        $this->app->singleton(TwoFactorLoginResponse::class, fn () => new class implements TwoFactorLoginResponse
        {
            public function toResponse($request)
            {
                return redirect()->intended(route('dashboard'));
            }
        });

        // Dopo il logout si torna alla pagina di login
        $this->app->singleton(LogoutResponse::class, fn () => new class implements LogoutResponse
        {
            public function toResponse($request)
            {
                return redirect()->route('login');
            }
        });
    }

    public function boot(): void
    {
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));

        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);

        /*
         * Autenticazione personalizzata sulla tabella `utenti`:
         * - messaggio unico "Credenziali non valide." per email inesistente o password errata
         *   (nessuna user enumeration, come nel legacy);
         * - account disattivato: messaggio dedicato (comportamento legacy preservato).
         */
        Fortify::authenticateUsing(function (Request $request): ?Utente {
            $utente = Utente::query()
                ->where('email', Str::lower((string) $request->input('email')))
                ->first();

            if (! $utente || ! Hash::check((string) $request->input('password'), (string) $utente->password_hash)) {
                return null;
            }

            if (! $utente->attivo) {
                throw ValidationException::withMessages([
                    Fortify::username() => 'Il tuo account è stato disattivato.',
                ]);
            }

            return $utente;
        });

        // Rate limiting login: max 5 tentativi al minuto per combinazione email+IP
        RateLimiter::for('login', function (Request $request) {
            $email = Str::lower((string) $request->input(Fortify::username()));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        // Rate limiting sulla challenge 2FA: 5 tentativi al minuto per sessione di login
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });
    }
}
