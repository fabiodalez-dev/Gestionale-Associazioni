<?php

namespace App\Http\Middleware\AreaSoci;

use App\Support\LegameSessione;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;

/**
 * Variante di 'auth' per il portale soci: i visitatori non autenticati
 * vengono rediretti alla login dell'area soci (non a quella admin).
 */
class AutenticaSocio extends Authenticate
{
    protected function redirectTo(Request $request): ?string
    {
        return $request->expectsJson() ? null : route('area-soci.login');
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

        if (! LegameSessione::valida($request, 'socio')) {
            LegameSessione::chiudi($request, 'socio');

            $this->unauthenticated($request, ['socio']);
        }
    }
}
