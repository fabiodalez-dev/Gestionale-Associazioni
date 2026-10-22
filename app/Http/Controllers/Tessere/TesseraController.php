<?php

namespace App\Http\Controllers\Tessere;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tessere\GeneraMassivoTessereRequest;
use App\Http\Requests\Tessere\StoreTesseraRequest;
use App\Http\Requests\Tessere\UpdateTesseraRequest;
use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Socio;
use App\Models\Tessera;
use App\Services\Tessere\TesseraIssuer;
use App\Services\Tessere\TesseraPdfRenderer;
use App\Services\Tessere\TesseraQrService;
use App\Settings\TesseramentoSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TesseraController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly TesseraIssuer $issuer,
        private readonly TesseraQrService $qr,
        private readonly TesseraPdfRenderer $pdf,
    ) {}

    /**
     * Lista tessere con filtri (anno, ricerca, stato), grafici aggregati
     * e modalità AJAX (?ajax=1 → JSON {count, html}). Spec §4.6–4.8.
     */
    /**
     * Export CSV delle tessere con gli stessi filtri dell'elenco (anno, ricerca, stato).
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', Tessera::class);

        $anno = (int) $request->input('anno', now()->year);
        $query = $this->queryFiltrata($anno, trim((string) $request->input('search', '')), (string) $request->input('stato', 'all'))
            ->with(['socio.tipoSocio'])
            ->select('tessere.*')
            ->orderBy('soci.cognome');

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Numero tessera', 'Numero socio', 'Cognome', 'Nome', 'Email', 'Tipo socio', 'Anno', 'Emessa il', 'Scade il', 'Stato', 'Template'], ';');
            foreach ($query->cursor() as $tessera) {
                if (! $tessera instanceof Tessera) {
                    continue;
                }
                fputcsv($out, [
                    $tessera->numero_tessera,
                    $tessera->socio?->numero_socio,
                    $tessera->socio?->cognome,
                    $tessera->socio?->nome,
                    $tessera->socio?->email,
                    $tessera->socio?->tipoSocio?->nome,
                    $tessera->anno_validita,
                    $tessera->data_emissione->format('d/m/Y'),
                    $tessera->data_scadenza->format('d/m/Y'),
                    $tessera->stato,
                    $tessera->template_tessera,
                ], ';');
            }
            fclose($out);
        }, 'tessere-'.$anno.'-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function index(Request $request): View|JsonResponse
    {
        $this->authorize('viewAny', Tessera::class);

        $associazione = $this->associazioneCorrente();

        $annoCorrente = (int) now()->year;
        $anno = (int) $request->input('anno', $annoCorrente);
        $search = trim((string) $request->input('search', ''));
        $stato = (string) $request->input('stato', 'all');

        $query = $this->queryFiltrata($anno, $search, $stato);

        $tessere = (clone $query)
            ->with(['socio.tipoSocio', 'eventoCreazione'])
            ->select('tessere.*')
            ->orderByDesc('tessere.anno_validita')
            ->orderBy('soci.cognome')
            ->get();

        $sogliaInScadenza = $associazione?->tesseramento()->giorni_notifica_scadenza ?? TesseramentoSettings::GIORNI_PREAVVISO_DEFAULT;

        if ($request->boolean('ajax')) {
            return response()->json([
                'count' => $tessere->count(),
                'html' => view('tessere.partials.risultati', [
                    'tessere' => $tessere,
                    'sogliaInScadenza' => $sogliaInScadenza,
                ])->render(),
            ]);
        }

        // Dropdown anni: DISTINCT anno_validita + anno corrente (spec §4.6)
        $anni = Tessera::query()
            ->distinct()
            ->pluck('anno_validita')
            ->map(fn ($a) => (int) $a)
            ->push($annoCorrente)
            ->unique()
            ->sortDesc()
            ->values();

        // Grafici aggregati per tipo socio (spec §4.8), stessi filtri della lista
        $aggregati = (clone $query)
            ->leftJoin('tipi_socio', 'soci.tipo_socio_id', '=', 'tipi_socio.id')
            ->join('associazioni', 'associazioni.id', '=', 'tessere.associazione_id')
            ->selectRaw("COALESCE(tipi_socio.nome, tessere.template_tessera, 'Default') as tipo")
            ->selectRaw('COUNT(*) as cnt')
            ->selectRaw('SUM(COALESCE(tipi_socio.costo_tessera, associazioni.costo_tessera, 0)) as ricavi')
            ->groupBy('tipo')
            ->orderByDesc('cnt')
            ->toBase()
            ->get()
            ->map(fn ($r) => [
                'tipo' => (string) $r->tipo,
                'cnt' => (int) $r->cnt,
                'ricavi' => (int) round((float) $r->ricavi),
            ]);

        $sociAttivi = Socio::query()
            ->where('stato', Socio::STATO_ATTIVO)
            ->orderBy('cognome')
            ->orderBy('nome')
            ->get(['id', 'nome', 'cognome', 'numero_socio']);

        $eventi = Evento::query()
            ->orderByDesc('data_evento')
            ->get(['id', 'titolo', 'data_evento']);

        return view('tessere.index', [
            'tessere' => $tessere,
            'anni' => $anni,
            'anno' => $anno,
            'search' => $search,
            'stato' => $stato,
            'aggregati' => $aggregati,
            'sociAttivi' => $sociAttivi,
            'eventi' => $eventi,
            'tipoScadenza' => $associazione ? $this->issuer->tipoScadenza($associazione) : Tessera::TIPO_SCADENZA_SOLARE,
            'sogliaInScadenza' => $sogliaInScadenza,
            'numeroSuggerito' => $annoCorrente.'0001',
            'annoCorrente' => $annoCorrente,
        ]);
    }

    /**
     * Creazione tessera singola (con email "rinnovo_tessera" best-effort).
     */
    public function store(StoreTesseraRequest $request): RedirectResponse
    {
        $dati = $request->validated();

        /** @var Socio $socio */
        $socio = Socio::query()->findOrFail($dati['socio_id']);

        $this->issuer->emetti($socio, [
            'anno_validita' => (int) $dati['anno_validita'],
            'numero_tessera' => $dati['numero_tessera'],
            'data_emissione' => $dati['data_emissione'],
            'data_scadenza' => $dati['data_scadenza'] ?? null,
            'stato' => $dati['stato'],
            'evento_creazione_id' => $dati['evento_creazione_id'] ?? null,
        ], accodaEmail: true);

        return redirect()
            ->route('tessere.index', ['anno' => $dati['anno_validita']])
            ->with('success', 'Tessera creata con successo.');
    }

    /**
     * Modifica tessera. tipo_scadenza resta forzato al default associazione;
     * nessuna email all'update (spec §4.2).
     */
    public function update(UpdateTesseraRequest $request, Tessera $tessera): RedirectResponse
    {
        $dati = $request->validated();

        $associazione = $this->associazioneCorrente();
        $tipoScadenza = $associazione
            ? $this->issuer->tipoScadenza($associazione)
            : $tessera->tipo_scadenza;

        $dataScadenza = $dati['data_scadenza'] ?? null;
        if (empty($dataScadenza)) {
            $dataScadenza = $this->issuer
                ->calcolaScadenza($tipoScadenza, (int) $dati['anno_validita'], Carbon::parse($dati['data_emissione']))
                ->toDateString();
        }

        $tessera->update([
            'socio_id' => $dati['socio_id'],
            'numero_tessera' => $dati['numero_tessera'],
            'anno_validita' => (int) $dati['anno_validita'],
            'data_emissione' => $dati['data_emissione'],
            'data_scadenza' => $dataScadenza,
            'stato' => $dati['stato'],
            'tipo_scadenza' => $tipoScadenza,
            'evento_creazione_id' => $dati['evento_creazione_id'] ?? null,
        ]);

        return redirect()
            ->route('tessere.index', ['anno' => $dati['anno_validita']])
            ->with('success', 'Tessera aggiornata con successo.');
    }

    /**
     * Eliminazione (hard delete come il legacy; audit via activity log).
     */
    public function destroy(Tessera $tessera): RedirectResponse
    {
        $this->authorize('delete', $tessera);

        $tessera->delete();

        return redirect()
            ->route('tessere.index')
            ->with('success', 'Tessera eliminata.');
    }

    /**
     * Generazione massiva "Genera Tessere {anno}" (spec §4.3).
     */
    public function generaMassivo(GeneraMassivoTessereRequest $request): RedirectResponse
    {
        $associazione = $this->associazioneCorrente();

        if ($associazione === null) {
            return redirect()->route('tessere.index')->with('error', 'Nessuna associazione selezionata.');
        }

        $anno = (int) $request->validated('anno_validita');

        try {
            $generate = $this->issuer->generaMassivo($associazione, $anno, $request->validated('evento_creazione_id'));
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('tessere.index', ['anno' => $anno])
                ->with('error', 'Errore durante la generazione massiva. Riprova più tardi.');
        }

        return redirect()
            ->route('tessere.index', ['anno' => $anno])
            ->with('success', "Generate {$generate} nuove tessere per l'anno {$anno}.");
    }

    /**
     * Emissione/rinnovo on-demand per socio (fix bug legacy §6.6: l'emissione
     * è un POST esplicito, il download PDF resta un GET idempotente).
     */
    public function emetti(Socio $socio): RedirectResponse
    {
        $this->authorize('create', Tessera::class);

        $tessera = $this->issuer->emettiSeAssente($socio);

        return redirect()->route('tessere.pdf', $tessera);
    }

    /**
     * Download PDF della tessera (GET idempotente, layout legacy §6.5).
     */
    public function pdf(Tessera $tessera): Response
    {
        $this->authorize('view', $tessera);

        // Compat dati legacy: qr_code_url persistito se mancante
        if (empty($tessera->qr_code_url)) {
            $tessera->qr_code_url = $this->qr->verificationUrl($tessera);
            $tessera->save();
        }

        return $this->pdf->render($tessera);
    }

    /**
     * Stampa tessera formato card 350×210 px (HTML stampabile, spec §7).
     */
    public function stampa(Tessera $tessera): View
    {
        $this->authorize('view', $tessera);

        $tessera->load('socio');
        $associazione = $tessera->associazione;

        $qrDataUri = $this->qr->generateDataUri(
            $tessera->qr_code_url ?: $this->qr->verificationUrl($tessera),
            3
        );

        return view('tessere.stampa-card', [
            'tessera' => $tessera,
            'socio' => $tessera->socio,
            'associazione' => $associazione,
            'qrDataUri' => $qrDataUri,
        ]);
    }

    /**
     * Query base filtrata (lista + grafici condividono le stesse condizioni).
     */
    private function queryFiltrata(int $anno, string $search, string $stato): Builder
    {
        $query = Tessera::query()
            ->join('soci', 'tessere.socio_id', '=', 'soci.id')
            ->whereNull('soci.deleted_at')
            ->where('tessere.anno_validita', $anno);

        if ($search !== '') {
            foreach (preg_split('/\s+/', $search) ?: [] as $parola) {
                $like = '%'.addcslashes($parola, '%_\\').'%';
                $query->where(function (Builder $q) use ($like) {
                    $q->where('soci.nome', 'like', $like)
                        ->orWhere('soci.cognome', 'like', $like)
                        ->orWhere('soci.numero_socio', 'like', $like)
                        ->orWhere('tessere.numero_tessera', 'like', $like);
                });
            }
        }

        // Filtri stato con semantica coerente (fix incoerenza legacy §9.2):
        // 'Attiva' = valida di fatto, 'Scaduta' = scaduta di fatto o marcata tale.
        match ($stato) {
            Tessera::STATO_ATTIVA => $query->valide(),
            Tessera::STATO_SCADUTA => $query->scaduteEffettive(),
            Tessera::STATO_SOSPESA, Tessera::STATO_ANNULLATA => $query->where('tessere.stato', $stato),
            default => $query,
        };

        return $query;
    }

    private function associazioneCorrente(): ?Associazione
    {
        $id = session('associazione_id');

        return $id ? Associazione::query()->find($id) : null;
    }
}
