<?php

namespace App\Services\Struttura;

use Closure;
use Illuminate\Http\Request;
use Spatie\Honeypot\SpamResponder\SpamResponder;
use Symfony\Component\HttpFoundation\Response;

/**
 * Risposta al traffico bot sul form pubblico di preiscrizione:
 * FINTO SUCCESSO senza scrivere nulla, come nel legacy (spec §6.4, §9.12).
 */
class FakeSuccessSpamResponder implements SpamResponder
{
    public function respond(Request $request, Closure $next): Response
    {
        return redirect()
            ->back()
            ->with('success', 'Richiesta inviata con successo.');
    }
}
