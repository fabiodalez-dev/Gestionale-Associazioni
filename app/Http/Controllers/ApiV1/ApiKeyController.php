<?php

namespace App\Http\Controllers\ApiV1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApiV1\StoreApiKeyRequest;
use App\Models\ApiKey;
use App\Models\Associazione;
use App\Models\Utente;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pagina amministrativa "Chiavi API" (parità legacy pages/api_keys.php §2).
 *
 * Visibilità per ruolo:
 * - super_admin senza associazione selezionata → tutte le chiavi (globali prima);
 * - super_admin con associazione selezionata → chiavi del tenant + globali;
 * - admin_associazione / operatore → solo chiavi della propria associazione.
 */
class ApiKeyController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', ApiKey::class);

        /** @var Utente $utente */
        $utente = $request->user();
        $tenant = $this->tenantCorrente($utente);

        $query = ApiKey::query()->with('associazione:id,nome');

        if ($utente->isSuperAdmin()) {
            if ($tenant !== null) {
                $query->where(fn ($q) => $q->whereNull('associazione_id')->orWhere('associazione_id', $tenant));
            }
            // Senza tenant selezionato: tutte (vista globale)
        } else {
            $query->where('associazione_id', $tenant ?? '__nessuna__');
        }

        $chiavi = $query
            ->orderByRaw('CASE WHEN associazione_id IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('created_at')
            ->get();

        return view('api-v1.api-keys', [
            'chiavi' => $chiavi,
            'permessiDisponibili' => StoreApiKeyRequest::PERMESSI,
            'vistaGlobale' => $utente->isSuperAdmin() && $tenant === null,
            'tokens' => $utente->tokens()->orderByDesc('created_at')->get(),
        ]);
    }

    public function store(StoreApiKeyRequest $request): RedirectResponse
    {
        /** @var Utente $utente */
        $utente = $request->user();
        $tenant = $this->tenantCorrente($utente);

        $isGlobale = $utente->isSuperAdmin() && $request->boolean('globale');

        if (! $isGlobale && $tenant === null) {
            return back()
                ->withInput()
                ->with('error', 'Seleziona un\'associazione prima di creare una chiave non globale.');
        }

        $permessi = array_values(array_unique($request->validated('permessi') ?? []));

        if ($permessi === []) {
            $permessi = ['tessere:read'];
        }

        // 256 bit esadecimali; salvata in chiaro per parità con i client legacy
        // (il mascheramento primi 8 + ultimi 4 è solo in UI).
        $chiave = bin2hex(random_bytes(32));

        ApiKey::create([
            'associazione_id' => $isGlobale ? null : $tenant,
            'api_key' => $chiave,
            'nome' => $request->validated('nome'),
            'permessi' => $permessi,
            'attiva' => true,
            'scadenza' => $request->validated('scadenza') ?: null,
        ]);

        return redirect()
            ->route('api-keys.index')
            ->with('success', 'Chiave API creata con successo. Copiala ora: non sarà più visibile per intero.')
            ->with('nuova_chiave', $chiave);
    }

    public function toggle(Request $request, ApiKey $apiKey): RedirectResponse
    {
        $this->authorize('update', $apiKey);

        $apiKey->update(['attiva' => ! $apiKey->attiva]);

        return redirect()
            ->route('api-keys.index')
            ->with('success', $apiKey->attiva ? 'Chiave attivata.' : 'Chiave disattivata.');
    }

    public function destroy(Request $request, ApiKey $apiKey): RedirectResponse
    {
        $this->authorize('delete', $apiKey);

        $apiKey->delete();

        return redirect()
            ->route('api-keys.index')
            ->with('success', 'Chiave API eliminata.');
    }

    private function tenantCorrente(Utente $utente): ?string
    {
        if ($utente->isSuperAdmin()) {
            return session('associazione_id');
        }

        return $utente->associazione_id ?: session('associazione_id');
    }
}
