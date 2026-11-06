<?php

namespace App\Http\Controllers\Struttura;

use App\Http\Controllers\Controller;
use App\Models\Associazione;
use App\Models\ConflittoRiunione;
use App\Models\DichiarazioneInteressi;
use App\Models\Riunione;
use App\Models\Socio;
use App\Services\Struttura\AssembleaService;
use App\Services\Struttura\ConflittiService;
use App\Services\Struttura\ConflittoNonValidoException;
use App\Settings\PartecipazioneSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Conflitti di interesse, lato segreteria (issue #11): registro annuale
 * delle dichiarazioni dei titolari di cariche e dei conflitti dichiarati
 * nelle riunioni, raccolta con promemoria, PDF per l'organo di controllo,
 * conflitti per punto dell'OdG. Riservato a chi gestisce le riunioni
 * (RiunionePolicy): le dichiarazioni non sono visibili agli altri soci.
 */
class ConflittoController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ConflittiService $conflitti,
        private readonly AssembleaService $assemblea,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Riunione::class);

        $anno = $this->anno($request);

        return view('struttura.conflitti.index', $this->dati($anno) + [
            'raccoltaAnno' => $this->associazione($request)->impostazioni(PartecipazioneSettings::class)->dichiarazioni_anno_campagna,
        ]);
    }

    public function pdf(Request $request): Response
    {
        $this->authorize('viewAny', Riunione::class);

        $anno = $this->anno($request);

        return Pdf::loadView('struttura.conflitti.pdf', $this->dati($anno) + [
            'associazione' => $this->associazione($request),
        ])->download("conflitti-interesse-{$anno}.pdf");
    }

    public function avviaRaccolta(Request $request): RedirectResponse
    {
        $this->authorize('create', Riunione::class);

        $anno = $this->anno($request);
        $inviati = $this->conflitti->avviaRaccolta($this->associazione($request), $anno);

        return redirect()
            ->route('conflitti.index', ['anno' => $anno])
            ->with('success', "Raccolta {$anno} avviata: richiesta inviata a {$inviati} titolari. Chi non dichiara riceverà un promemoria al mese.");
    }

    public function fermaRaccolta(Request $request): RedirectResponse
    {
        $this->authorize('create', Riunione::class);

        $this->conflitti->fermaRaccolta($this->associazione($request));

        return redirect()->route('conflitti.index', ['anno' => $this->anno($request)])->with('success', 'Raccolta fermata: niente più promemoria.');
    }

    /**
     * Dichiarazione registrata dalla segreteria (modulo cartaceo).
     */
    public function registraDichiarazione(Request $request): RedirectResponse
    {
        $this->authorize('create', Riunione::class);

        $dati = $request->validate([
            'socio_id' => ['required', 'uuid'],
            'anno' => ['required', 'integer', 'min:2000', 'max:2100'],
            'nessun_conflitto' => ['nullable', 'boolean'],
            'interessi' => ['nullable', 'string', 'max:5000'],
        ]);

        $socio = Socio::query()->withoutGlobalScope('sede')->findOrFail($dati['socio_id']);

        try {
            $this->conflitti->dichiara($socio, (int) $dati['anno'], (bool) ($dati['nessun_conflitto'] ?? false), $dati['interessi'] ?? null, $request->user());
        } catch (ConflittoNonValidoException $e) {
            return redirect()->route('conflitti.index', ['anno' => $dati['anno']])->with('error', $e->getMessage());
        }

        return redirect()->route('conflitti.index', ['anno' => $dati['anno']])->with('success', "Dichiarazione di {$socio->nome_completo} registrata.");
    }

    public function storeConflitto(Request $request, Riunione $riunione): RedirectResponse
    {
        $this->authorize('update', $riunione);

        $dati = $request->validate([
            'punto_indice' => ['required', 'integer', 'min:0'],
            'socio' => ['required', 'string', 'max:255'],
            'motivazione' => ['nullable', 'string', 'max:1000'],
        ]);

        // Socio da id (elenco del direttivo) o da codice (assemblea)
        $socioId = Socio::query()->withoutGlobalScope('sede')->whereKey($dati['socio'])->value('id')
            ?? $this->assemblea->socioIdDaCodice($dati['socio']);
        $socio = $socioId !== null ? Socio::query()->withoutGlobalScope('sede')->find($socioId) : null;

        if ($socio === null) {
            return redirect()->route('riunioni.show', $riunione)->with('error', 'Socio non trovato.');
        }

        try {
            $this->conflitti->dichiaraConflitto($riunione, (int) $dati['punto_indice'], $socio, $dati['motivazione'] ?? null);
        } catch (ConflittoNonValidoException $e) {
            return redirect()->route('riunioni.show', $riunione)->with('error', $e->getMessage());
        }

        return redirect()->route('riunioni.show', $riunione)->with('success', "Conflitto di interesse di {$socio->nome_completo} registrato: non voterà sul punto.");
    }

    public function destroyConflitto(Riunione $riunione, ConflittoRiunione $conflitto): RedirectResponse
    {
        $this->authorize('update', $riunione);
        abort_unless($conflitto->riunione_id === $riunione->id, 404);

        $conflitto->delete();

        return redirect()->route('riunioni.show', $riunione)->with('success', 'Conflitto di interesse rimosso.');
    }

    /**
     * @return array<string, mixed>
     */
    private function dati(int $anno): array
    {
        $associazione = Associazione::query()->findOrFail(session('associazione_id') ?? auth()->user()?->associazione_id);

        $dichiarazioni = DichiarazioneInteressi::query()->where('anno', $anno)->get()->keyBy('socio_id');

        $conflitti = ConflittoRiunione::query()
            ->whereHas('riunione', fn ($q) => $q->whereYear('data_ora', $anno))
            ->with(['riunione:id,titolo,data_ora,odg', 'socio' => fn ($q) => $q->withoutGlobalScope('sede')->withTrashed()])
            ->get()
            ->sortBy(fn (ConflittoRiunione $c) => $c->riunione?->data_ora)
            ->values();

        return [
            'anno' => $anno,
            'titolari' => $this->conflitti->titolari($associazione, $anno),
            'dichiarazioni' => $dichiarazioni,
            'conflitti' => $conflitti,
        ];
    }

    private function anno(Request $request): int
    {
        $anno = (int) $request->input('anno', now()->year);

        return $anno >= 2000 && $anno <= 2100 ? $anno : (int) now()->year;
    }

    private function associazione(Request $request): Associazione
    {
        return Associazione::query()->findOrFail(session('associazione_id') ?? $request->user()->associazione_id);
    }
}
