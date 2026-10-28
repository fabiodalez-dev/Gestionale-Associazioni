<?php

namespace App\Http\Controllers\Struttura;

use App\Http\Controllers\Controller;
use App\Models\Candidatura;
use App\Models\Elezione;
use App\Models\Riunione;
use App\Models\SchedaElezione;
use App\Models\Socio;
use App\Services\Struttura\AssembleaService;
use App\Services\Struttura\ElezioneNonValidaException;
use App\Services\Struttura\ElezioniService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Elezioni e rinnovo delle cariche, lato segreteria (issue #9): creazione
 * dall'assemblea, candidature, apertura del voto, scrutinio con le schede
 * cartacee e proclamazione. Governance: RiunionePolicy sull'assemblea.
 */
class ElezioneController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ElezioniService $elezioni,
        private readonly AssembleaService $assemblea,
    ) {}

    public function store(Request $request, Riunione $riunione): RedirectResponse
    {
        $this->authorize('update', $riunione);

        if (! $riunione->isAssemblea() || $riunione->stato !== Riunione::STATO_PIANIFICATA) {
            return redirect()->route('riunioni.show', $riunione)->with('warning', 'Le elezioni si organizzano in un\'assemblea non ancora svolta.');
        }

        $dati = $request->validate([
            'organo' => ['required', Rule::in(array_keys(Elezione::ORGANI))],
            'posti' => ['required', 'integer', 'min:1', 'max:50'],
            'max_preferenze' => ['required', 'integer', 'min:1', 'lte:posti'],
            'durata_mandato_mesi' => ['required', 'integer', 'min:1', 'max:120'],
            'candidature_dal' => ['required', 'date'],
            'candidature_al' => ['required', 'date', 'after_or_equal:candidature_dal', 'before_or_equal:'.$riunione->data_ora->toDateString()],
            'anzianita_minima_mesi' => ['nullable', 'integer', 'min:0', 'max:600'],
            'regola_parita' => ['required', Rule::in(array_keys(Elezione::REGOLE_PARITA))],
        ], [
            'max_preferenze.lte' => 'Le preferenze non possono superare i posti.',
            'candidature_al.before_or_equal' => 'Le candidature devono chiudersi entro il giorno dell\'assemblea.',
        ]);

        $elezione = Elezione::create($dati + [
            'associazione_id' => $riunione->associazione_id,
            'riunione_id' => $riunione->id,
            'anzianita_minima_mesi' => (int) ($dati['anzianita_minima_mesi'] ?? 0),
        ]);

        return redirect()->route('elezioni.show', $elezione)->with('success', 'Elezione creata: le candidature si raccolgono nel periodo indicato.');
    }

    public function show(Elezione $elezione): View
    {
        $this->authorize('view', $elezione->riunione);

        $elezione->load([
            'riunione',
            'candidature' => fn ($q) => $q->orderByRaw('posizione IS NULL')->orderBy('posizione')->orderBy('created_at'),
            'candidature.socio' => fn ($q) => $q->withoutGlobalScope('sede')->withTrashed(),
        ]);

        return view('struttura.elezioni.show', [
            'elezione' => $elezione,
            'riunione' => $elezione->riunione,
            'schedeOnline' => SchedaElezione::query()->where('elezione_id', $elezione->id)->count(),
            'quorum' => $this->assemblea->quorum($elezione->riunione),
        ]);
    }

    public function destroy(Elezione $elezione): RedirectResponse
    {
        $this->authorize('update', $elezione->riunione);

        if ($elezione->stato !== Elezione::STATO_CANDIDATURE) {
            return $this->indietro($elezione, 'warning', 'Un\'elezione con il voto già aperto non si elimina.');
        }

        $riunione = $elezione->riunione;
        $elezione->delete();

        return redirect()->route('riunioni.show', $riunione)->with('success', 'Elezione eliminata.');
    }

    public function candida(Request $request, Elezione $elezione): RedirectResponse
    {
        $this->authorize('update', $elezione->riunione);

        $dati = $request->validate([
            'codice' => ['required', 'string', 'max:255'],
            'presentazione' => ['nullable', 'string', 'max:2000'],
            'accettata' => ['nullable', 'boolean'],
        ]);

        $socioId = $this->assemblea->socioIdDaCodice($dati['codice']);
        $socio = $socioId !== null ? Socio::query()->withoutGlobalScope('sede')->find($socioId) : null;

        if ($socio === null) {
            return $this->indietro($elezione, 'error', "Nessun socio corrisponde al codice \"{$dati['codice']}\".");
        }

        return $this->esegui($elezione, fn () => $this->elezioni->candida(
            $elezione,
            $socio,
            'segreteria',
            $dati['presentazione'] ?? null,
            (bool) ($dati['accettata'] ?? false),
        ), "Candidatura di {$socio->nome_completo} registrata.");
    }

    public function accetta(Elezione $elezione, Candidatura $candidatura): RedirectResponse
    {
        $this->authorize('update', $elezione->riunione);
        abort_unless($candidatura->elezione_id === $elezione->id, 404);

        return $this->esegui($elezione, fn () => $this->elezioni->accetta($candidatura), 'Accettazione della candidatura registrata.');
    }

    public function ritira(Elezione $elezione, Candidatura $candidatura): RedirectResponse
    {
        $this->authorize('update', $elezione->riunione);
        abort_unless($candidatura->elezione_id === $elezione->id, 404);

        return $this->esegui($elezione, fn () => $this->elezioni->ritira($candidatura), 'Candidatura ritirata.');
    }

    public function apriVoto(Elezione $elezione): RedirectResponse
    {
        $this->authorize('update', $elezione->riunione);

        return $this->esegui($elezione, fn () => $this->elezioni->apriVoto($elezione), 'Voto aperto.');
    }

    public function scrutinio(Request $request, Elezione $elezione): RedirectResponse
    {
        $this->authorize('update', $elezione->riunione);

        $dati = $request->validate([
            'preferenze' => ['nullable', 'array'],
            'preferenze.*' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'schede_valide' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'schede_bianche' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'schede_nulle' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        $preferenze = array_map('intval', array_filter($dati['preferenze'] ?? [], fn ($v) => $v !== null && $v !== ''));

        return $this->esegui($elezione, fn () => $this->elezioni->scrutina(
            $elezione,
            $preferenze,
            (int) ($dati['schede_valide'] ?? 0),
            (int) ($dati['schede_bianche'] ?? 0),
            (int) ($dati['schede_nulle'] ?? 0),
        ), 'Scrutinio completato: controlla i risultati e proclama gli eletti.');
    }

    public function proclama(Elezione $elezione): RedirectResponse
    {
        $this->authorize('update', $elezione->riunione);

        try {
            $eletti = $this->elezioni->proclama($elezione);
        } catch (ElezioneNonValidaException $e) {
            return $this->indietro($elezione, 'error', $e->getMessage());
        }

        return $this->indietro($elezione, 'success', "Eletti proclamati: {$eletti} nuove cariche registrate, cariche uscenti chiuse. Assegna ora i ruoli interni dalla pagina degli organi sociali.");
    }

    private function esegui(Elezione $elezione, callable $azione, string $successo): RedirectResponse
    {
        try {
            $azione();
        } catch (ElezioneNonValidaException $e) {
            return $this->indietro($elezione, 'error', $e->getMessage());
        }

        return $this->indietro($elezione, 'success', $successo);
    }

    private function indietro(Elezione $elezione, string $tipo, string $messaggio): RedirectResponse
    {
        return redirect()->route('elezioni.show', $elezione)->with($tipo, $messaggio);
    }
}
