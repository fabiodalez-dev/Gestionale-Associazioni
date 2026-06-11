<?php

namespace App\Http\Controllers\Struttura;

use App\Http\Controllers\Controller;
use App\Http\Requests\Struttura\AccettaInvitoRequest;
use App\Models\Utente;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Accettazione dell'invito amministratore: pagina pubblica raggiungibile
 * solo tramite link firmato temporaneo (middleware 'signed', 7 giorni).
 */
class InvitoController extends Controller
{
    public function show(Utente $utente): View
    {
        return view('struttura.invito.show', ['utente' => $utente]);
    }

    public function store(AccettaInvitoRequest $request, Utente $utente): RedirectResponse
    {
        $utente->password_hash = $request->validated('password'); // hashed dal cast
        $utente->attivo = true;
        $utente->save();

        return redirect()
            ->route('login')
            ->with('status', 'Password impostata con successo: ora puoi accedere.');
    }
}
