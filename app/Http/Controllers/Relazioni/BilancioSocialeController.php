<?php

namespace App\Http\Controllers\Relazioni;

use App\Http\Controllers\Controller;
use App\Models\Associazione;
use App\Models\BilancioSociale;
use App\Models\Documento;
use App\Models\Verbale;
use App\Services\Relazioni\BilancioSocialeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Bilancio sociale annuale (issue #15): generazione con i dati del
 * gestionale, testi per sezione, rigenerazione dei dati, approvazione con
 * il verbale dell'assemblea, PDF e pubblicazione in Trasparenza.
 * Governance: BilancioSocialePolicy (documenti.*).
 */
class BilancioSocialeController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly BilancioSocialeService $bilanci,
    ) {}

    public function index(): View
    {
        $this->authorize('viewAny', BilancioSociale::class);

        return view('relazioni.bilancio-sociale.index', [
            'bilanci' => BilancioSociale::query()->orderByDesc('anno')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', BilancioSociale::class);

        $dati = $request->validate(['anno' => ['required', 'integer', 'min:2000', 'max:'.(now()->year)]]);

        if (BilancioSociale::query()->where('anno', $dati['anno'])->exists()) {
            return redirect()->route('bilancio-sociale.index')->with('warning', "Il bilancio sociale {$dati['anno']} esiste già.");
        }

        $bilancio = $this->bilanci->genera($this->associazione($request), (int) $dati['anno']);

        return redirect()->route('bilancio-sociale.show', $bilancio)->with('success', 'Bilancio sociale creato con i dati del gestionale: completa i testi delle sezioni.');
    }

    public function show(BilancioSociale $bilancio): View
    {
        $this->authorize('view', $bilancio);

        return view('relazioni.bilancio-sociale.show', [
            'bilancio' => $bilancio->load(['verbale', 'documento']),
            'verbali' => Verbale::query()
                ->whereIn('tipo_riunione', ['Assemblea Ordinaria', 'Assemblea Straordinaria'])
                ->orderByDesc('data_riunione')
                ->limit(30)
                ->get(['id', 'titolo', 'data_riunione', 'numero', 'anno']),
        ]);
    }

    public function update(Request $request, BilancioSociale $bilancio): RedirectResponse
    {
        $this->authorize('update', $bilancio);

        // I testi approvati dall'assemblea non si cambiano: prima si riapre
        if ($bilancio->stato !== BilancioSociale::STATO_BOZZA) {
            return redirect()->route('bilancio-sociale.show', $bilancio)->with('warning', 'Il bilancio è approvato: riportalo in bozza per modificare i testi.');
        }

        $dati = $request->validate([
            'testi' => ['nullable', 'array'],
            'testi.*' => ['nullable', 'string', 'max:20000'],
        ]);

        $testi = array_intersect_key($dati['testi'] ?? [], BilancioSociale::SEZIONI);
        $bilancio->update(['testi' => array_map(fn ($t) => $t === null ? null : trim($t), $testi)]);

        return redirect()->route('bilancio-sociale.show', $bilancio)->with('success', 'Testi salvati.');
    }

    public function rigenera(Request $request, BilancioSociale $bilancio): RedirectResponse
    {
        $this->authorize('update', $bilancio);

        if ($bilancio->stato !== BilancioSociale::STATO_BOZZA) {
            return redirect()->route('bilancio-sociale.show', $bilancio)->with('warning', 'I dati di un bilancio approvato non si rigenerano.');
        }

        $this->bilanci->genera($this->associazione($request), $bilancio->anno);

        return redirect()->route('bilancio-sociale.show', $bilancio)->with('success', 'Dati aggiornati dal gestionale; i testi restano com\'erano.');
    }

    public function approva(Request $request, BilancioSociale $bilancio): RedirectResponse
    {
        $this->authorize('update', $bilancio);

        if ($bilancio->stato !== BilancioSociale::STATO_BOZZA) {
            return redirect()->route('bilancio-sociale.show', $bilancio)->with('warning', 'Il bilancio sociale è già approvato.');
        }

        $dati = $request->validate([
            'verbale_id' => ['nullable', 'uuid', Rule::exists('verbali', 'id')->where('associazione_id', $bilancio->associazione_id)],
        ]);

        $bilancio->update([
            'stato' => BilancioSociale::STATO_APPROVATO,
            'verbale_id' => $dati['verbale_id'] ?? null,
            'approvato_at' => now(),
        ]);

        return redirect()->route('bilancio-sociale.show', $bilancio)->with('success', 'Bilancio sociale approvato.');
    }

    public function riapri(BilancioSociale $bilancio): RedirectResponse
    {
        $this->authorize('update', $bilancio);

        if ($bilancio->stato === BilancioSociale::STATO_PUBBLICATO) {
            return redirect()->route('bilancio-sociale.show', $bilancio)->with('warning', 'Il bilancio è pubblicato: togli prima il PDF dalla Trasparenza.');
        }

        $bilancio->update(['stato' => BilancioSociale::STATO_BOZZA, 'approvato_at' => null]);

        return redirect()->route('bilancio-sociale.show', $bilancio)->with('success', 'Bilancio riportato in bozza.');
    }

    public function pdf(Request $request, BilancioSociale $bilancio): Response
    {
        $this->authorize('view', $bilancio);

        return $this->renderPdf($request, $bilancio)->download("bilancio-sociale-{$bilancio->anno}.pdf");
    }

    /**
     * Pubblica il PDF tra i documenti della sezione Trasparenza.
     */
    public function pubblica(Request $request, BilancioSociale $bilancio): RedirectResponse
    {
        $this->authorize('update', $bilancio);

        if ($bilancio->stato !== BilancioSociale::STATO_APPROVATO) {
            return redirect()->route('bilancio-sociale.show', $bilancio)->with('warning', 'Si pubblica un bilancio sociale approvato.');
        }

        $contenuto = $this->renderPdf($request, $bilancio)->output();
        $nome = "bilancio-sociale-{$bilancio->anno}.pdf";

        DB::transaction(function () use ($bilancio, $contenuto, $nome, $request): void {
            $documento = Documento::create([
                'nome_file' => $nome,
                'percorso_file' => '',
                'descrizione' => "Bilancio sociale {$bilancio->anno}",
                'categoria' => 'Bilancio sociale',
                'pubblico' => true,
                'categoria_pubblica' => 'bilancio',
                'dimensione_kb' => (int) ceil(strlen($contenuto) / 1024),
                'caricato_da' => $request->user()->id,
            ]);

            $media = $documento->addMediaFromString($contenuto)
                ->usingFileName(bin2hex(random_bytes(16)).'.pdf')
                ->usingName($nome)
                ->toMediaCollection(Documento::COLLEZIONE_FILE);

            $documento->update(['percorso_file' => $media->getPathRelativeToRoot()]);

            $bilancio->update([
                'stato' => BilancioSociale::STATO_PUBBLICATO,
                'documento_id' => $documento->id,
                'pubblicato_at' => now(),
            ]);
        });

        return redirect()->route('bilancio-sociale.show', $bilancio)->with('success', 'Bilancio sociale pubblicato nella sezione Trasparenza.');
    }

    private function renderPdf(Request $request, BilancioSociale $bilancio): \Barryvdh\DomPDF\PDF
    {
        return Pdf::loadView('relazioni.bilancio-sociale.pdf', [
            'bilancio' => $bilancio->load('verbale'),
            'associazione' => $this->associazione($request),
        ]);
    }

    private function associazione(Request $request): Associazione
    {
        return Associazione::query()->findOrFail(session('associazione_id') ?? $request->user()->associazione_id);
    }
}
