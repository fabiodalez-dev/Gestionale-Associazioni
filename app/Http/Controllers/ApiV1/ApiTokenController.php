<?php

namespace App\Http\Controllers\ApiV1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApiV1\StoreApiTokenRequest;
use App\Models\Utente;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Personal access token Sanctum per l'API v1 (miglioramento 23).
 *
 * Ogni utente amministrativo gestisce SOLO i propri token; le abilities
 * usano la stessa sintassi dei permessi delle chiavi legacy.
 */
class ApiTokenController extends Controller
{
    public function store(StoreApiTokenRequest $request): RedirectResponse
    {
        /** @var Utente $utente */
        $utente = $request->user();

        $scadenza = $request->validated('scadenza');

        // Nessun token senza scadenza: se non indicata vale la durata massima
        // (la FormRequest rifiuta già date oltre il limite).
        $scadenzaEffettiva = $scadenza
            ? Carbon::parse($scadenza)->endOfDay()
            : now()->addDays(StoreApiTokenRequest::SCADENZA_MASSIMA_GIORNI)->endOfDay();

        $token = $utente->createToken(
            $request->validated('nome'),
            array_values(array_unique($request->validated('abilities'))),
            $scadenzaEffettiva
        );

        return redirect()
            ->route('api-keys.index')
            ->with('success', 'Token creato con successo. Copialo ora: non sarà più visibile.')
            ->with('nuovo_token', $token->plainTextToken);
    }

    public function destroy(Request $request, string $tokenId): RedirectResponse
    {
        /** @var Utente $utente */
        $utente = $request->user();

        // Solo i propri token: nessun accesso ai token altrui
        $utente->tokens()->where('id', $tokenId)->delete();

        return redirect()
            ->route('api-keys.index')
            ->with('success', 'Token revocato.');
    }
}
