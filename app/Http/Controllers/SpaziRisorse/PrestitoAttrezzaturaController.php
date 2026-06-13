<?php

namespace App\Http\Controllers\SpaziRisorse;

use App\Http\Controllers\Controller;
use App\Http\Requests\SpaziRisorse\PrestitoAttrezzaturaRequest;
use App\Models\Attrezzatura;
use App\Models\PrestitoAttrezzatura;
use App\Models\Socio;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Prestiti delle attrezzature ai soci (f.10 piano "Organizzazione 3"):
 * registrazione con disponibilità calcolata e blocco se non disponibile
 * (Form Request), azione "registra restituzione", storico per socio.
 *
 * Isolamento tenant garantito dal global scope BelongsToAssociazione.
 */
class PrestitoAttrezzaturaController extends Controller
{
    use AuthorizesRequests;

    /**
     * Elenco prestiti con filtro stato (attivi / in ritardo / restituiti)
     * e form di nuovo prestito.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', PrestitoAttrezzatura::class);

        $filtro = (string) $request->query('filtro', 'attivi');
        $filtro = in_array($filtro, ['attivi', 'ritardo', 'restituiti', 'tutti'], true) ? $filtro : 'attivi';

        $prestiti = PrestitoAttrezzatura::query()
            ->with(['attrezzatura', 'socio'])
            ->when($filtro === 'attivi', fn ($q) => $q->attivi())
            ->when($filtro === 'ritardo', fn ($q) => $q->inRitardo())
            ->when($filtro === 'restituiti', fn ($q) => $q->whereNotNull('restituito_at'))
            ->orderByDesc('dal')
            ->paginate(25)
            ->withQueryString();

        // Attrezzature con disponibilità calcolata per il select del form
        $attrezzature = Attrezzatura::query()
            ->withSum(['prestiti as quantita_in_prestito' => fn ($q) => $q->whereNull('restituito_at')], 'quantita')
            ->orderBy('nome')
            ->get();

        return view('spazi-risorse.prestiti.index', [
            'prestiti' => $prestiti,
            'attrezzature' => $attrezzature,
            'soci' => Socio::query()->orderBy('cognome')->orderBy('nome')->get(),
            'filtro' => $filtro,
        ]);
    }

    public function store(PrestitoAttrezzaturaRequest $request): RedirectResponse
    {
        $this->authorize('create', PrestitoAttrezzatura::class);

        $validated = $request->validated();
        $attrezzaturaId = (string) $validated['attrezzatura_id'];
        $richiesta = (int) $validated['quantita'];

        // Ricontrollo della disponibilità DENTRO la transazione con lock
        // applicativo sulla riga attrezzatura: serializza i prestiti concorrenti
        // sulla stessa attrezzatura, così che la somma dei pezzi in prestito non
        // superi la quantità totale (la validazione del Form Request è un
        // check-then-act non atomico aggirabile sotto concorrenza).
        DB::transaction(function () use ($validated, $attrezzaturaId, $richiesta): void {
            $attrezzatura = $this->lockAttrezzatura($attrezzaturaId);

            if ($attrezzatura === null) {
                return; // già coperto da exists nel Form Request
            }

            $disponibile = $attrezzatura->quantitaDisponibile();

            if ($richiesta > $disponibile) {
                throw ValidationException::withMessages([
                    'quantita' => sprintf(
                        'Disponibilità insufficiente: richiesti %d pezzi ma solo %d disponibili (su %d totali).',
                        $richiesta,
                        $disponibile,
                        (int) $attrezzatura->quantita,
                    ),
                ]);
            }

            PrestitoAttrezzatura::create($validated);
        });

        return redirect()
            ->route('prestiti.index')
            ->with('success', 'Prestito registrato con successo.');
    }

    /**
     * Lock applicativo sulla riga attrezzatura (mutex per-attrezzatura). Su
     * sqlite (test) lockForUpdate è un no-op ma la transazione serializza
     * comunque; su MySQL (produzione) il lock chiude la race sui prestiti.
     */
    private function lockAttrezzatura(string $attrezzaturaId): ?Attrezzatura
    {
        return Attrezzatura::query()
            ->whereKey($attrezzaturaId)
            ->when(
                DB::connection()->getDriverName() !== 'sqlite',
                fn ($q) => $q->lockForUpdate()
            )
            ->first();
    }

    /**
     * Registra la restituzione: imposta restituito_at = ora, riaprendo la
     * disponibilità dell'attrezzatura. Idempotente (no-op se già restituito).
     */
    public function restituisci(PrestitoAttrezzatura $prestito): RedirectResponse
    {
        $this->authorize('update', $prestito);

        if ($prestito->isAttivo()) {
            $prestito->update(['restituito_at' => now()]);
        }

        return redirect()
            ->back()
            ->with('success', 'Restituzione registrata con successo.');
    }

    public function destroy(PrestitoAttrezzatura $prestito): RedirectResponse
    {
        $this->authorize('delete', $prestito);

        $prestito->delete();

        return redirect()
            ->route('prestiti.index')
            ->with('success', 'Prestito eliminato con successo.');
    }
}
