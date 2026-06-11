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

        $token = $utente->createToken(
            $request->validated('nome'),
            array_values(array_unique($request->validated('abilities'))),
            $scadenza ? Carbon::parse($scadenza)->endOfDay() : null
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
