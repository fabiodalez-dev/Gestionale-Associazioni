<?php

namespace App\Http\Middleware;

use App\Models\Utente;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lo stato `attivo` dell'utente staff vale a ogni richiesta, non solo al
 * login con password: un utente disattivato dal super admin perde subito
 * la sessione aperta e il remember-me, invece di restare dentro fino alla
 * scadenza (come accade per soci e account con EnsureSocioValido /
 * EnsureAccountValido).
 */
class EnsureUtenteAttivo
{
    public function handle(Request $request, Closure $next): Response
    {
        $utente = $request->user('web');

        if ($utente instanceof Utente && ! $utente->attivo) {
            Auth::guard('web')->logout();
            // Il tenant selezionato non sopravvive al logout forzato
            $request->session()->forget(['associazione_id', 'associazione_nome']);
            // regenerate (non invalidate): le altre guard nello stesso browser sopravvivono
            $request->session()->regenerate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Il tuo account è stato disattivato. Contatta l\'amministratore.',
            ]);
        }

        return $next($request);
    }
}
