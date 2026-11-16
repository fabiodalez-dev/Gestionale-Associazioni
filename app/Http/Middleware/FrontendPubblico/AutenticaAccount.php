<?php

namespace App\Http\Middleware\FrontendPubblico;

use App\Models\Associazione;
use App\Support\LegameSessione;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Variante di 'auth' per il frontend pubblico: i visitatori non autenticati
 * vengono rediretti alla login pubblica DEL TENANT corrente
 * (pubblico.login, /a/{slug}/login), non a quella admin né a quella soci.
 */
class AutenticaAccount extends Authenticate
{
    protected function redirectTo(Request $request): ?string
    {
        if ($request->expectsJson()) {
            return null;
        }

        $slug = static::slugTenantCorrente($request);

        if ($slug !== null && Route::has('pubblico.login')) {
            return route('pubblico.login', ['associazione' => $slug]);
        }

        return null;
    }

    /**
     * Slug del tenant corrente dal parametro di rotta {associazione:slug}.
     */
    public static function slugTenantCorrente(Request $request): ?string
    {
        $associazione = $request->route('associazione');

        if ($associazione instanceof Associazione) {
            return $associazione->slug;
        }

        return is_string($associazione) ? $associazione : null;
    }

    /**
     * Oltre all'autenticazione, la sessione deve essere ancora legata alla
     * credenziale con cui è nata (password ed email verificata).
     *
     * @param  Request  $request
     * @param  array<int, string>  $guards
     */
    protected function authenticate($request, array $guards): void
    {
        parent::authenticate($request, $guards);

        if (! LegameSessione::valida($request, 'account')) {
            LegameSessione::chiudi($request, 'account');

            $this->unauthenticated($request, ['account']);
        }
    }
}
