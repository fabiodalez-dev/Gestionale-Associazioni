<?php

namespace App\Http\Controllers\Struttura;

use App\Http\Controllers\Controller;
use App\Http\Requests\Struttura\AccettaInvitoRequest;
use App\Models\Utente;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Accettazione dell'invito amministratore: pagina pubblica raggiungibile
 * solo tramite link firmato temporaneo (middleware 'signed', 7 giorni).
 *
 * La firma da sola non basta: il link porta anche un nonce casuale il cui
 * hash è salvato sull'utente. Così l'invito è monouso (consumato al primo
 * utilizzo), un nuovo invito invalida i precedenti e un link ancora valido
 * non può riattivare un utente disattivato né sovrascrivere una password
 * impostata nel frattempo dal super admin.
 */
class InvitoController extends Controller
{
    public function show(Request $request, Utente $utente): View
    {
        abort_unless($utente->invitoValido($request->query('nonce')), 403, 'Questo invito non è più valido: chiedi un nuovo invito all\'amministratore.');

        return view('struttura.invito.show', ['utente' => $utente]);
    }

    public function store(AccettaInvitoRequest $request, Utente $utente): RedirectResponse
    {
        abort_unless($utente->invitoValido($request->query('nonce')), 403, 'Questo invito non è più valido: chiedi un nuovo invito all\'amministratore.');

        $utente->forceFill([
            'password_hash' => $request->validated('password'), // hashed dal cast
            ...Utente::invitoChiuso(),
            'remember_token' => Str::random(60),
        ])->save();

        return redirect()
            ->route('login')
            ->with('status', 'Password impostata con successo: ora puoi accedere.');
    }
}
