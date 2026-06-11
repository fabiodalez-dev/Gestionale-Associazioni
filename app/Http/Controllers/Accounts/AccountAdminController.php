<?php

namespace App\Http\Controllers\Accounts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\CollegaSocioRequest;
use App\Models\Account;
use App\Models\Socio;
use App\Services\Soci\SocioService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Gestione admin degli account del frontend pubblico
 * (docs/PIANO-PRENOTAZIONI.md, sezione Admin: "accounts").
 *
 * - lista con ricerca, stato verifica, n. prenotazioni e badge
 *   "richiesta socio" pendente;
 * - dettaglio con prenotazioni e collegamento al socio: il match per
 *   email è SOLO un suggerimento, il collegamento è sempre un'azione
 *   esplicita dell'admin (anti account-takeover, socio_id unique);
 * - promozione a socio: crea il Socio dall'anagrafica dell'account
 *   (stato Attivo, numero socio progressivo via SocioService) e collega;
 * - disattivazione/riattivazione dell'account.
 *
 * Isolamento tenant: il global scope BelongsToAssociazione filtra
 * lettura e binding; un account di un altro tenant produce 404.
 */
class AccountAdminController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly SocioService $soci) {}

    /**
     * Lista account del tenant con ricerca su nome/cognome/email e
     * filtro "richieste socio pendenti".
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Account::class);

        $search = trim((string) $request->query('search', ''));
        $soloRichieste = $request->boolean('richieste');

        $accounts = Account::query()
            ->withCount('prenotazioni')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('nome', 'like', "%{$search}%")
                        ->orWhere('cognome', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($soloRichieste, function ($query) {
                $query->whereNotNull('richiesta_socio_at')->whereNull('socio_id');
            })
            ->orderBy('cognome')
            ->orderBy('nome')
            ->paginate(25)
            ->withQueryString();

        $richiestePendenti = Account::query()
            ->whereNotNull('richiesta_socio_at')
            ->whereNull('socio_id')
            ->where('attivo', true)
            ->count();

        return view('accounts.index', [
            'accounts' => $accounts,
            'search' => $search,
            'soloRichieste' => $soloRichieste,
            'richiestePendenti' => $richiestePendenti,
        ]);
    }

    /**
     * Dettaglio account: anagrafica, prenotazioni, collegamento socio
     * con match suggerito per email (solo suggerimento, mai automatico).
     */
    public function show(Request $request, Account $account): View
    {
        $this->authorize('view', $account);

        $account->load('socio');

        $prenotazioni = $account->prenotazioni()
            ->with(['evento' => fn ($q) => $q->withTrashed()])
            ->orderByDesc('created_at')
            ->paginate(25);

        $sociCollegabili = collect();
        $socioSuggerito = null;

        if ($account->socio_id === null) {
            // Soci del tenant non già collegati ad altri account
            $sociCollegabili = Socio::query()
                ->whereNotIn('id', Account::allTenants()->withTrashed()->whereNotNull('socio_id')->select('socio_id'))
                ->orderBy('cognome')
                ->orderBy('nome')
                ->get(['id', 'nome', 'cognome', 'numero_socio', 'email']);

            // Match suggerito per email (azione comunque esplicita)
            $socioSuggerito = filled($account->email)
                ? $sociCollegabili->first(
                    fn (Socio $socio) => filled($socio->email)
                        && mb_strtolower($socio->email) === mb_strtolower($account->email)
                )
                : null;
        }

        return view('accounts.show', [
            'account' => $account,
            'prenotazioni' => $prenotazioni,
            'sociCollegabili' => $sociCollegabili,
            'socioSuggerito' => $socioSuggerito,
        ]);
    }

    /**
     * Collega l'account a un socio esistente (vincoli nel Form Request:
     * stesso tenant, account non già collegato, socio non già collegato).
     */
    public function collegaSocio(CollegaSocioRequest $request, Account $account): RedirectResponse
    {
        $this->authorize('gestisci', $account);

        $account->forceFill(['socio_id' => $request->validated('socio_id')])->save();

        return redirect()
            ->route('accounts.show', $account)
            ->with('success', 'Account collegato al socio con successo.');
    }

    /**
     * Promuove l'account a socio: crea il Socio dall'anagrafica con stato
     * Attivo (numero socio progressivo via SocioService) e lo collega.
     */
    public function promuoviSocio(Request $request, Account $account): RedirectResponse
    {
        $this->authorize('gestisci', $account);

        if ($account->socio_id !== null) {
            return redirect()
                ->route('accounts.show', $account)
                ->with('error', 'Questo account è già collegato a un socio.');
        }

        $socio = $this->soci->crea([
            'associazione_id' => $account->associazione_id,
            'nome' => $account->nome,
            'cognome' => $account->cognome,
            'email' => $account->email,
            'telefono' => $account->telefono,
            'data_iscrizione' => now()->toDateString(),
            'stato' => Socio::STATO_ATTIVO,
            'privacy_consenso' => $account->privacy_accettata_at !== null,
        ], generaTessera: false);

        $account->forceFill(['socio_id' => $socio->id])->save();

        return redirect()
            ->route('accounts.show', $account)
            ->with('success', "Socio n. {$socio->numero_socio} creato e collegato all'account.");
    }

    /**
     * Disattiva l'account (blocca login e prenotazioni dal frontend).
     */
    public function disattiva(Request $request, Account $account): RedirectResponse
    {
        $this->authorize('gestisci', $account);

        $account->forceFill(['attivo' => false])->save();

        return redirect()
            ->route('accounts.show', $account)
            ->with('success', 'Account disattivato.');
    }

    /**
     * Riattiva un account precedentemente disattivato.
     */
    public function riattiva(Request $request, Account $account): RedirectResponse
    {
        $this->authorize('gestisci', $account);

        $account->forceFill(['attivo' => true])->save();

        return redirect()
            ->route('accounts.show', $account)
            ->with('success', 'Account riattivato.');
    }
}
