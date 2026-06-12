<?php

namespace App\Http\Controllers\Registro;

use App\Http\Controllers\Controller;
use App\Http\Requests\Registro\SalvaCaricaRequest;
use App\Models\Carica;
use App\Models\Socio;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Organi sociali (f.16 del piano vita sociale): gestione delle cariche
 * per tenant (ruolo, socio, inizio/fine mandato, note) con vista delle
 * cariche attive e dello storico, e avviso dei mandati in scadenza nei
 * prossimi 90 giorni (lo stesso alert compare in dashboard).
 */
class CaricaController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Carica::class);

        $oggi = now()->toDateString();

        $attive = Carica::query()
            ->attive()
            ->with(['socio' => fn ($q) => $q->withTrashed()])
            ->orderBy('ruolo')
            ->orderBy('inizio')
            ->get();

        // Storiche: mandato concluso (fine nel passato)
        $storiche = Carica::query()
            ->whereNotNull('fine')
            ->whereDate('fine', '<', $oggi)
            ->with(['socio' => fn ($q) => $q->withTrashed()])
            ->orderByDesc('fine')
            ->paginate(25, ['*'], 'storiche')
            ->withQueryString();

        $inScadenza = Carica::query()
            ->inScadenza()
            ->with(['socio' => fn ($q) => $q->withTrashed()])
            ->orderBy('fine')
            ->get();

        // Edit flow legacy: ?edit=<uuid> precompila il form nel modal auto-aperto
        $caricaInModifica = null;
        if ($request->filled('edit')) {
            $caricaInModifica = Carica::query()->find($request->query('edit'));
        }

        $soci = Socio::query()
            ->orderBy('cognome')
            ->orderBy('nome')
            ->get(['id', 'nome', 'cognome', 'numero_socio']);

        return view('registro.organi-sociali.index', [
            'attive' => $attive,
            'storiche' => $storiche,
            'inScadenza' => $inScadenza,
            'caricaInModifica' => $caricaInModifica,
            'soci' => $soci,
            'ruoli' => Carica::RUOLI,
        ]);
    }

    public function store(SalvaCaricaRequest $request): RedirectResponse
    {
        Carica::create($request->validated());

        return redirect()->route('organi-sociali.index')->with('success', 'Carica registrata con successo!');
    }

    public function update(SalvaCaricaRequest $request, Carica $carica): RedirectResponse
    {
        $carica->update($request->validated());

        return redirect()->route('organi-sociali.index')->with('success', 'Carica aggiornata con successo!');
    }

    public function destroy(Request $request, Carica $carica): RedirectResponse
    {
        $this->authorize('delete', $carica);

        $carica->delete();

        return redirect()->route('organi-sociali.index')->with('success', 'Carica eliminata con successo!');
    }
}
