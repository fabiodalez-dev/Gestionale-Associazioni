<?php

namespace App\Http\Controllers\Struttura;

use App\Http\Controllers\Controller;
use App\Models\Associazione;
use App\Models\Proposta;
use App\Models\Riunione;
use App\Services\Struttura\PropostaNonValidaException;
use App\Services\Struttura\ProposteService;
use App\Settings\PartecipazioneSettings;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Proposte dei soci, lato segreteria (issue #10): elenco per stato,
 * moderazione, inserimento nell'ordine del giorno di una riunione, esito e
 * impostazioni (soglia di adesioni, moderazione).
 * Governance: PropostaPolicy, famiglia di permessi proposte.*.
 */
class PropostaController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ProposteService $proposte,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Proposta::class);

        $stato = (string) $request->query('stato', 'attive');
        $search = trim((string) $request->query('search', ''));

        $attive = [Proposta::STATO_IN_REVISIONE, Proposta::STATO_PUBBLICATA, Proposta::STATO_DA_ESAMINARE, Proposta::STATO_IN_ODG];

        $proposte = Proposta::query()
            ->with(['proponente' => fn ($q) => $q->withoutGlobalScope('sede')->withTrashed()])
            ->withCount('adesioni')
            ->when($stato === 'attive', fn ($q) => $q->whereIn('stato', $attive))
            ->when(array_key_exists($stato, Proposta::STATI), fn ($q) => $q->where('stato', $stato))
            ->when($search !== '', fn ($q) => $q->where('titolo', 'like', "%{$search}%"))
            ->orderByRaw("CASE stato WHEN 'da_esaminare' THEN 0 WHEN 'in_revisione' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        /** @var Associazione $associazione */
        $associazione = Associazione::query()->findOrFail(session('associazione_id') ?? $request->user()->associazione_id);

        return view('struttura.proposte.index', [
            'proposte' => $proposte,
            'stato' => $stato,
            'search' => $search,
            'impostazioni' => $associazione->impostazioni(PartecipazioneSettings::class),
            'soglia' => $this->proposte->soglia($associazione),
        ]);
    }

    public function show(Proposta $proposta): View
    {
        $this->authorize('view', $proposta);

        $proposta->load([
            'proponente' => fn ($q) => $q->withoutGlobalScope('sede')->withTrashed(),
            'riunione',
            'adesioni' => fn ($q) => $q->orderBy('created_at'),
            'adesioni.socio' => fn ($q) => $q->withoutGlobalScope('sede')->withTrashed(),
        ]);

        $riunioni = Riunione::query()
            ->where('stato', Riunione::STATO_PIANIFICATA)
            ->when(
                $proposta->destinatario === 'assemblea',
                fn ($q) => $q->whereIn('organo', [Riunione::ORGANO_ASSEMBLEA_ORDINARIA, Riunione::ORGANO_ASSEMBLEA_STRAORDINARIA]),
                fn ($q) => $q->where('organo', Riunione::ORGANO_DIRETTIVO),
            )
            ->orderBy('data_ora')
            ->get(['id', 'titolo', 'data_ora', 'organo']);

        return view('struttura.proposte.show', [
            'proposta' => $proposta,
            'riunioni' => $riunioni,
            'soglia' => $this->proposte->soglia($proposta->associazione),
        ]);
    }

    public function pubblica(Proposta $proposta): RedirectResponse
    {
        $this->authorize('update', $proposta);

        return $this->esegui($proposta, fn () => $this->proposte->pubblica($proposta), 'Proposta pubblicata: i soci possono aderire.');
    }

    public function nonAmmettere(Request $request, Proposta $proposta): RedirectResponse
    {
        $this->authorize('update', $proposta);

        $dati = $request->validate(['motivazione' => ['required', 'string', 'max:2000']]);

        return $this->esegui($proposta, fn () => $this->proposte->nonAmmettere($proposta, $dati['motivazione']), 'Proposta non ammessa: il proponente è stato avvisato.');
    }

    public function inserisciInOdg(Request $request, Proposta $proposta): RedirectResponse
    {
        $this->authorize('update', $proposta);

        $dati = $request->validate([
            'riunione_id' => ['required', 'uuid', Rule::exists('riunioni', 'id')->where('associazione_id', $proposta->associazione_id)],
        ]);

        $riunione = Riunione::query()->findOrFail($dati['riunione_id']);
        $this->authorize('update', $riunione);

        return $this->esegui($proposta, fn () => $this->proposte->inserisciInOdg($proposta, $riunione), "Proposta inserita nell'ordine del giorno di \"{$riunione->titolo}\".");
    }

    public function esito(Request $request, Proposta $proposta): RedirectResponse
    {
        $this->authorize('update', $proposta);

        $dati = $request->validate([
            'esito' => ['required', Rule::in(['accolta', 'respinta'])],
            'motivazione' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->esegui($proposta, fn () => $this->proposte->registraEsito($proposta, $dati['esito'] === 'accolta', $dati['motivazione'] ?? null), 'Esito registrato.');
    }

    public function impostazioni(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', Proposta::class);
        abort_unless($request->user()->can('proposte.update') || $request->user()->isAdminAssociazione() || $request->user()->isSuperAdmin(), 403);

        $dati = $request->validate([
            'proposte_soglia' => ['required', 'integer', 'min:1', 'max:10000'],
            'proposte_soglia_percentuale' => ['boolean'],
            'proposte_moderazione' => ['boolean'],
        ]);

        if ($request->boolean('proposte_soglia_percentuale') && (int) $dati['proposte_soglia'] > 100) {
            return back()->withErrors(['proposte_soglia' => 'In percentuale la soglia non può superare 100.']);
        }

        Associazione::query()->findOrFail(session('associazione_id') ?? $request->user()->associazione_id)
            ->impostazioni(PartecipazioneSettings::class)
            ->aggiorna([
                'proposte_soglia' => (int) $dati['proposte_soglia'],
                'proposte_soglia_percentuale' => $request->boolean('proposte_soglia_percentuale'),
                'proposte_moderazione' => $request->boolean('proposte_moderazione'),
            ]);

        return redirect()->route('proposte.index')->with('success', 'Impostazioni delle proposte salvate.');
    }

    private function esegui(Proposta $proposta, callable $azione, string $successo): RedirectResponse
    {
        try {
            $azione();
        } catch (PropostaNonValidaException $e) {
            return redirect()->route('proposte.show', $proposta)->with('error', $e->getMessage());
        }

        return redirect()->route('proposte.show', $proposta)->with('success', $successo);
    }
}
