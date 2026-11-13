<?php

namespace App\Http\Controllers\ApiV1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApiV1\StoreApiTokenRequest;
use App\Models\Utente;
use App\Policies\PersonalAccessTokenPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Personal access token Sanctum per l'API v1 (miglioramento 23).
 *
 * Ogni utente amministrativo crea e revoca i propri token; le abilities
 * usano la stessa sintassi dei permessi delle chiavi legacy. Gli
 * amministratori vedono e revocano anche i token altrui secondo
 * PersonalAccessTokenPolicy (super_admin: tutti; admin_associazione: utenti
 * della propria associazione, mai super admin né altri tenant).
 */
class ApiTokenController extends Controller
{
    public function __construct(private readonly PersonalAccessTokenPolicy $policy) {}

    /**
     * Pagina "Token API": token di tutti gli utenti gestibili, per la revoca.
     */
    public function index(Request $request): View
    {
        /** @var Utente $utente */
        $utente = $request->user();

        abort_unless($this->policy->viewAny($utente), 403);

        $utentiGestibili = $this->policy->queryUtentiGestibili(Utente::query(), $utente)->select('id');

        $tokens = PersonalAccessToken::query()
            ->where('tokenable_type', (new Utente)->getMorphClass())
            ->whereIn('tokenable_id', $utentiGestibili)
            ->with('tokenable')
            ->orderByDesc('created_at')
            ->get();

        return view('api-v1.api-tokens', [
            'tokens' => $tokens,
            'vistaGlobale' => $utente->isSuperAdmin(),
        ]);
    }

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

        /** @var PersonalAccessToken|null $token */
        $token = PersonalAccessToken::query()->find($tokenId);

        // Token inesistente (già revocato, doppio click): stesso esito di prima
        if ($token === null) {
            return redirect()
                ->route('api-keys.index')
                ->with('success', 'Token revocato.');
        }

        abort_unless($this->policy->revoca($utente, $token), 403);

        $proprio = $token->tokenable_type === $utente->getMorphClass()
            && (string) $token->tokenable_id === (string) $utente->getKey();

        $token->delete();

        // I propri token si revocano dalla pagina "Chiavi API", quelli altrui
        // dalla pagina "Token API"
        return redirect()
            ->route($proprio ? 'api-keys.index' : 'api-tokens.index')
            ->with('success', 'Token revocato.');
    }
}
