<?php

namespace App\Http\Middleware;

use App\Models\Utente;
use App\Services\Update\UpdateService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Il super admin vede e modifica i dati di tutte le associazioni: senza un
 * secondo fattore una password rubata basta a prendersi la piattaforma.
 * Finché non conferma la 2FA (two_factor_confirmed_at) dopo il login può
 * raggiungere solo la pagina profilo per attivarla, le rotte Fortify della
 * 2FA, la conferma password e il logout; il resto lo rimanda lì.
 *
 * Non tocca: rotte API (token Bearer: gruppo api, dove questo middleware
 * non è registrato), installer, pagina
 * aggiornamenti mentre un aggiornamento è in corso o la manutenzione è
 * accesa (chi aggiorna da una versione senza questo blocco non deve restare
 * a metà). SUPER_ADMIN_2FA_OBBLIGATORIA=false lo spegne del tutto.
 */
class EnsureSuperAdmin2fa
{
    public const MESSAGGIO = 'Per il super admin l\'autenticazione a due fattori è obbligatoria: attivala qui sotto per continuare a usare il gestionale.';

    /** Rotte sempre raggiungibili da un super admin senza 2FA */
    private const ROTTE_CONSENTITE = [
        'profile.show',
        'logout',
        'two-factor.*',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'user-password.update',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $utente = $request->user('web');

        if (! $utente instanceof Utente || ! self::richiesta($utente) || $this->esente($request)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => self::MESSAGGIO], 403);
        }

        // Il messaggio lo mostra la pagina profilo finché la 2FA non è attiva
        return redirect()->route('profile.show');
    }

    /** L'utente deve ancora attivare la 2FA obbligatoria? */
    public static function richiesta(Utente $utente): bool
    {
        return (bool) config('sicurezza.super_admin_2fa_obbligatoria', true)
            && $utente->isSuperAdmin()
            && $utente->two_factor_confirmed_at === null;
    }

    private function esente(Request $request): bool
    {
        // Le API REST stanno nel gruppo api e qui non arrivano; le rotte web
        // con prefisso api/ (es. api/utenti-search) usano la sessione e
        // restano soggette al blocco
        if ($request->is('install', 'install/*', 'up')) {
            return true;
        }

        if ($request->routeIs(...self::ROTTE_CONSENTITE)) {
            return true;
        }

        if (app()->isDownForMaintenance()) {
            return true;
        }

        // Aggiornamento in corso (anche quello che introduce questo blocco):
        // il polling dello stato e lo sblocco della manutenzione devono
        // continuare a rispondere
        if ($request->routeIs('aggiornamenti.*')) {
            $updater = app(UpdateService::class);

            return $updater->inCorso() || $updater->inManutenzione();
        }

        return false;
    }
}
