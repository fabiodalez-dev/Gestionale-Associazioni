<?php

namespace App\Http\Controllers\Relazioni;

use App\Http\Controllers\Controller;
use App\Models\OpportunitaCrm;
use App\Models\Utente;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * CRM a colonne (kanban): nuove adesioni, sponsor e volontari.
 * Il trascinamento delle schede (SortableJS) chiama sposta() con la nuova
 * fase e l'ordine della colonna di arrivo.
 */
class CrmPipelineController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', OpportunitaCrm::class);

        $pipeline = array_key_exists((string) $request->query('pipeline'), OpportunitaCrm::PIPELINE) ? (string) $request->query('pipeline') : 'adesioni';
        $fasi = OpportunitaCrm::fasi($pipeline);
        $finale = OpportunitaCrm::faseFinale($pipeline);

        $opportunita = OpportunitaCrm::query()
            ->with('responsabile:id,nome,cognome')
            ->where('pipeline', $pipeline)
            // Nella colonna finale restano le vinte degli ultimi 90 giorni; le perse escono dalla board
            ->where(fn ($q) => $q->whereNull('esito')->orWhere(fn ($q) => $q->where('esito', 'vinta')->where('chiusa_at', '>=', now()->subDays(90))))
            ->orderBy('posizione')
            ->orderBy('created_at')
            ->get()
            ->groupBy('fase');

        $aperte = OpportunitaCrm::query()->where('pipeline', $pipeline)->aperte();
        $chiuse90 = OpportunitaCrm::query()->where('pipeline', $pipeline)->whereNotNull('esito')->where('chiusa_at', '>=', now()->subDays(90));
        $vinte = (clone $chiuse90)->where('esito', 'vinta')->count();
        $totaleChiuse = (clone $chiuse90)->count();

        return view('relazioni.crm.index', [
            'pipeline' => $pipeline,
            'fasi' => $fasi,
            'finale' => $finale,
            'colonne' => $opportunita,
            'kpi' => [
                'valore' => (float) (clone $aperte)->sum('valore'),
                'aperte' => (clone $aperte)->count(),
                'conversione' => $totaleChiuse > 0 ? (int) round($vinte * 100 / $totaleChiuse) : null,
                'ritardo' => (clone $aperte)->whereNotNull('prossima_azione_il')->whereDate('prossima_azione_il', '<', now()->startOfDay())->count(),
            ],
            'perse' => OpportunitaCrm::query()->where('pipeline', $pipeline)->where('esito', 'persa')->latest('chiusa_at')->limit(20)->get(),
            // Utente non ha lo scope di tenant: filtro esplicito sull'associazione corrente
            'responsabili' => Utente::query()->where('associazione_id', OpportunitaCrm::currentAssociazioneId())->where('attivo', true)->orderBy('nome')->get(['id', 'nome', 'cognome']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', OpportunitaCrm::class);

        $dati = $this->valida($request);
        $dati['posizione'] = (int) OpportunitaCrm::query()->where('pipeline', $dati['pipeline'])->where('fase', $dati['fase'])->max('posizione') + 1;
        $dati['associazione_id'] = OpportunitaCrm::currentAssociazioneId();
        $opportunita = new OpportunitaCrm;
        $opportunita->fill($dati)->save();
        $this->aggiornaEsito($opportunita);

        return redirect()->route('crm.index', ['pipeline' => $dati['pipeline']])->with('success', '"'.$opportunita->titolo.'" aggiunto.');
    }

    public function update(Request $request, OpportunitaCrm $opportunita): RedirectResponse
    {
        $this->authorize('update', $opportunita);

        $dati = $this->valida($request, $opportunita);
        $opportunita->update($dati);
        $this->aggiornaEsito($opportunita);

        return redirect()->route('crm.index', ['pipeline' => $opportunita->pipeline])->with('success', 'Modifiche salvate.');
    }

    /**
     * Trascinamento: nuova fase e ordine completo della colonna di arrivo.
     */
    public function sposta(Request $request, OpportunitaCrm $opportunita): JsonResponse
    {
        $this->authorize('update', $opportunita);

        // Risposta sempre JSON (chiamata dal trascinamento): 422 con gli errori
        $validatore = Validator::make($request->all(), [
            'fase' => ['required', Rule::in(array_keys(OpportunitaCrm::fasi($opportunita->pipeline)))],
            'ordine' => ['required', 'array', 'max:500'],
            'ordine.*' => ['uuid'],
        ]);
        if ($validatore->fails()) {
            return response()->json(['ok' => false, 'errori' => $validatore->errors()], 422);
        }
        $dati = $validatore->validated();

        DB::transaction(function () use ($opportunita, $dati): void {
            $opportunita->update(['fase' => $dati['fase']]);
            // Solo le schede della stessa associazione e pipeline (lo scope tenant filtra il resto)
            $ids = OpportunitaCrm::query()->where('pipeline', $opportunita->pipeline)->whereIn('id', $dati['ordine'])->pluck('id')->all();
            foreach (array_values(array_filter($dati['ordine'], fn ($id) => in_array($id, $ids, true))) as $posizione => $id) {
                OpportunitaCrm::query()->whereKey($id)->update(['posizione' => $posizione, 'fase' => $dati['fase']]);
            }
            $this->aggiornaEsito($opportunita->refresh());
        });

        return response()->json(['ok' => true, 'esito' => $opportunita->refresh()->esito]);
    }

    public function persa(OpportunitaCrm $opportunita): RedirectResponse
    {
        $this->authorize('update', $opportunita);
        $opportunita->update(['esito' => 'persa', 'chiusa_at' => now()]);

        return back()->with('success', '"'.$opportunita->titolo.'" segnato come non andato a buon fine.');
    }

    public function riapri(OpportunitaCrm $opportunita): RedirectResponse
    {
        $this->authorize('update', $opportunita);
        $prima = array_key_first(OpportunitaCrm::fasi($opportunita->pipeline));
        $opportunita->update(['esito' => null, 'chiusa_at' => null, 'fase' => $opportunita->fase === OpportunitaCrm::faseFinale($opportunita->pipeline) ? $prima : $opportunita->fase]);

        return back()->with('success', '"'.$opportunita->titolo.'" riaperto.');
    }

    public function destroy(OpportunitaCrm $opportunita): RedirectResponse
    {
        $this->authorize('delete', $opportunita);
        $opportunita->delete();

        return back()->with('success', '"'.$opportunita->titolo.'" eliminato.');
    }

    /**
     * @return array<string, mixed>
     */
    private function valida(Request $request, ?OpportunitaCrm $opportunita = null): array
    {
        $pipeline = $opportunita !== null ? $opportunita->pipeline : (string) $request->input('pipeline');

        $dati = $request->validate([
            'pipeline' => [$opportunita ? 'prohibited' : 'required', Rule::in(array_keys(OpportunitaCrm::PIPELINE))],
            'fase' => ['required', Rule::in(array_keys(OpportunitaCrm::fasi($pipeline)))],
            'titolo' => ['required', 'string', 'max:255'],
            'descrizione' => ['nullable', 'string', 'max:500'],
            'origine' => ['nullable', Rule::in(array_keys(OpportunitaCrm::ORIGINI))],
            'valore' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'prossima_azione_il' => ['nullable', 'date'],
            'email' => ['nullable', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'responsabile_id' => ['nullable', Rule::exists('utenti', 'id')->where('associazione_id', OpportunitaCrm::currentAssociazioneId())],
        ], [], ['titolo' => 'nome', 'prossima_azione_il' => 'prossima azione', 'responsabile_id' => 'responsabile']);

        return $dati;
    }

    /**
     * Arrivata nell'ultima fase = vinta; tornata indietro = di nuovo aperta.
     */
    private function aggiornaEsito(OpportunitaCrm $opportunita): void
    {
        $finale = $opportunita->fase === OpportunitaCrm::faseFinale($opportunita->pipeline);

        if ($finale && $opportunita->esito !== 'vinta') {
            $opportunita->update(['esito' => 'vinta', 'chiusa_at' => now()]);
        } elseif (! $finale && $opportunita->esito === 'vinta') {
            $opportunita->update(['esito' => null, 'chiusa_at' => null]);
        }
    }
}
