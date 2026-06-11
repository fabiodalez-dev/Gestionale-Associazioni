<?php

namespace App\Http\Controllers\Eventi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Eventi\EventoRequest;
use App\Models\Associazione;
use App\Models\Evento;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CRUD eventi dell'associazione (pagina admin "eventi" del legacy).
 *
 * Isolamento tenant: il global scope BelongsToAssociazione filtra lettura,
 * binding e scrittura; un id di un altro tenant produce 404
 * (equivalente Laravel del "redirect silenzioso" legacy).
 */
class EventoController extends Controller
{
    use AuthorizesRequests;

    /**
     * Lista eventi con ricerca testuale, contatore tessere create
     * ed eventuale evento in modifica (?edit=...).
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Evento::class);

        $search = trim((string) $request->query('search', ''));

        $eventi = Evento::query()
            ->withCount('tessereCreate')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('titolo', 'like', "%{$search}%")
                        ->orWhere('descrizione', 'like', "%{$search}%")
                        ->orWhere('luogo', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('data_evento')
            ->paginate(25)
            ->withQueryString();

        // ?edit={id}: form precompilato; id altrui/inesistente → nessun errore (parità legacy)
        $editEvento = null;
        if ($request->filled('edit')) {
            $editEvento = Evento::query()->find($request->query('edit'));
        }

        // Associazione corrente: serve per il link "anteprima pubblica"
        // /a/{slug}/eventi/{id} (mostrato solo se lo slug esiste)
        $associazioneCorrente = Associazione::query()->find(Evento::currentAssociazioneId());

        return view('eventi.index', [
            'eventi' => $eventi,
            'editEvento' => $editEvento,
            'search' => $search,
            'slugAssociazione' => $associazioneCorrente?->slug,
            'frontendAttivo' => (bool) ($associazioneCorrente?->frontend_pubblico_attivo),
        ]);
    }

    public function store(EventoRequest $request): RedirectResponse
    {
        $this->authorize('create', Evento::class);

        Evento::create($request->validated());

        return redirect()
            ->route('eventi.index')
            ->with('success', 'Evento creato con successo.');
    }

    public function update(EventoRequest $request, Evento $evento): RedirectResponse
    {
        $this->authorize('update', $evento);

        $evento->update($request->validated());

        return redirect()
            ->route('eventi.index')
            ->with('success', 'Evento aggiornato con successo.');
    }

    /**
     * Eliminazione evento: i partecipanti vengono rimossi (cascade),
     * le tessere emesse durante l'evento restano (riferimento storico).
     */
    public function destroy(Evento $evento): RedirectResponse
    {
        $this->authorize('delete', $evento);

        $evento->delete();

        return redirect()
            ->route('eventi.index')
            ->with('success', 'Evento eliminato con successo.');
    }
}
