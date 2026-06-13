<?php

namespace App\Http\Controllers\VolontariTurni;

use App\Http\Controllers\Controller;
use App\Http\Requests\VolontariTurni\RegistraPresenzeRequest;
use App\Http\Requests\VolontariTurni\StoreTurnoRequest;
use App\Models\Evento;
use App\Models\Mansione;
use App\Models\Progetto;
use App\Models\Socio;
use App\Models\Spazio;
use App\Models\Turno;
use App\Models\TurnoAssegnazione;
use App\Services\VolontariTurni\CandidaturaService;
use App\Services\VolontariTurni\PostiTurnoService;
use App\Services\VolontariTurni\RegistroOreService;
use App\Services\VolontariTurni\SuggerimentiVolontari;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Pianificazione turni lato admin (f.3 del piano "Organizzazione 3"):
 * lista, vista per evento, dettaglio con assegnazione volontari (suggeriti
 * per mansione e disponibilità), gestione assegnazioni e registrazione
 * presenze a fine turno (f.5).
 */
class TurnoController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly SuggerimentiVolontari $suggerimenti,
        private readonly RegistroOreService $registro,
        private readonly CandidaturaService $candidature,
        private readonly PostiTurnoService $posti,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Turno::class);

        $stato = (string) $request->query('stato', 'all');
        $eventoId = (string) $request->query('evento', '');

        $turni = Turno::query()
            ->with(['mansione:id,nome', 'evento:id,titolo', 'spazio:id,nome'])
            ->withCount([
                'assegnazioni as confermati_count' => fn ($q) => $q->where('stato', TurnoAssegnazione::STATO_CONFERMATO),
                'assegnazioni as proposti_count' => fn ($q) => $q->where('stato', TurnoAssegnazione::STATO_PROPOSTO),
            ])
            // I turni-contenitore delle ore manuali non sono pianificazione.
            ->where('titolo', 'not like', RegistroOreService::PREFISSO_MANUALE.'%')
            ->when(in_array($stato, Turno::STATI, true), fn ($q) => $q->where('stato', $stato))
            ->when($eventoId !== '' && Evento::query()->whereKey($eventoId)->exists(),
                fn ($q) => $q->where('evento_id', $eventoId))
            ->orderByDesc('data')
            ->orderBy('ora_inizio')
            ->paginate(25)
            ->withQueryString();

        return view('volontari-turni.turni.index', [
            'turni' => $turni,
            'stato' => $stato,
            'eventoId' => $eventoId,
            'eventi' => Evento::query()->orderByDesc('data_evento')->limit(100)->get(['id', 'titolo', 'data_evento']),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Turno::class);

        return view('volontari-turni.turni.create', $this->datiForm());
    }

    public function store(StoreTurnoRequest $request): RedirectResponse
    {
        $this->authorize('create', Turno::class);

        $turno = Turno::create($request->datiTurno());

        return redirect()
            ->route('turni.show', $turno)
            ->with('success', 'Turno creato.');
    }

    public function show(Turno $turno): View
    {
        $this->authorize('view', $turno);

        $turno->load([
            'mansione', 'evento', 'spazio', 'progetto',
            'assegnazioni.socio:id,nome,cognome,numero_socio,email',
        ]);

        return view('volontari-turni.turni.show', [
            'turno' => $turno,
            'suggeriti' => $this->suggerimenti->perTurno($turno),
            'durataOre' => $turno->durataOre(),
        ]);
    }

    public function edit(Turno $turno): View
    {
        $this->authorize('update', $turno);

        return view('volontari-turni.turni.edit', $this->datiForm() + ['turno' => $turno]);
    }

    public function update(StoreTurnoRequest $request, Turno $turno): RedirectResponse
    {
        $this->authorize('update', $turno);

        $turno->update($request->datiTurno());

        return redirect()
            ->route('turni.show', $turno)
            ->with('success', 'Turno aggiornato.');
    }

    public function destroy(Turno $turno): RedirectResponse
    {
        $this->authorize('delete', $turno);

        $turno->delete();

        return redirect()
            ->route('turni.index')
            ->with('success', 'Turno eliminato.');
    }

    /**
     * Assegna un socio al turno (proposto o confermato a discrezione admin).
     */
    public function assegna(Request $request, Turno $turno): RedirectResponse
    {
        $this->authorize('update', $turno);

        $validated = $request->validate([
            'socio_id' => ['required', 'uuid'],
            'conferma' => ['nullable', 'boolean'],
        ]);

        if (! Socio::query()->whereKey($validated['socio_id'])->exists()) {
            return back()->with('error', 'Socio non valido.');
        }

        $stato = $request->boolean('conferma')
            ? TurnoAssegnazione::STATO_CONFERMATO
            : TurnoAssegnazione::STATO_PROPOSTO;

        // Check posti + create/update serializzati sul mutex per-turno
        // (stesso helper dell'auto-candidatura): niente sovra-assegnazione
        // se due operatori — o un operatore e un socio — agiscono in parallelo.
        try {
            $this->posti->occupa(
                $turno,
                $validated['socio_id'],
                $stato,
                richiediApertura: false,
                aggiornaSeAttivo: true,
            );
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first('turno') ?: 'Non ci sono posti disponibili.');
        }

        return redirect()
            ->route('turni.show', $turno)
            ->with('success', 'Volontario assegnato.');
    }

    /**
     * Conferma una candidatura proposta.
     */
    public function conferma(Turno $turno, TurnoAssegnazione $assegnazione): RedirectResponse
    {
        $this->authorize('update', $turno);
        abort_unless($assegnazione->turno_id === $turno->id, 404);

        // Conferma serializzata sul mutex per-turno: il ricontrollo posti
        // avviene DENTRO il lock (vedi PostiTurnoService::conferma).
        try {
            $this->posti->conferma($turno, $assegnazione);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first('turno') ?: 'Non ci sono posti disponibili per confermare.');
        }

        return back()->with('success', 'Candidatura confermata.');
    }

    /**
     * Rimuove (rinuncia lato admin) un'assegnazione: lo slot torna libero.
     */
    public function rimuovi(Turno $turno, TurnoAssegnazione $assegnazione): RedirectResponse
    {
        $this->authorize('update', $turno);
        abort_unless($assegnazione->turno_id === $turno->id, 404);

        $this->candidature->rinuncia($assegnazione, 'Rimosso dall\'organizzazione');

        return back()->with('success', 'Volontario rimosso dal turno.');
    }

    /**
     * Registra le presenze a fine turno: ore effettive nel registro (f.5).
     */
    public function registraPresenze(RegistraPresenzeRequest $request, Turno $turno): RedirectResponse
    {
        $this->authorize('update', $turno);

        $registrate = $this->registro->registraPresenze($turno, $request->orePerAssegnazione());

        return redirect()
            ->route('turni.show', $turno)
            ->with('success', "Presenze registrate ({$registrate}).");
    }

    /**
     * @return array<string, mixed>
     */
    private function datiForm(): array
    {
        return [
            'mansioni' => Mansione::query()->orderBy('nome')->get(['id', 'nome']),
            'eventi' => Evento::query()->orderByDesc('data_evento')->limit(100)->get(['id', 'titolo', 'data_evento']),
            'spazi' => Spazio::query()->where('attivo', true)->orderBy('nome')->get(['id', 'nome']),
            // f.14 piano "Organizzazione 3": progetti del tenant per la select sul turno
            'progetti' => Progetto::query()->orderBy('nome')->get(['id', 'nome']),
        ];
    }
}
