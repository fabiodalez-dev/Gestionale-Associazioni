<?php

namespace App\Http\Controllers\Rinnovi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rinnovi\StampaMassivaTessereRequest;
use App\Models\Associazione;
use App\Models\Tessera;
use App\Services\Rinnovi\StampaMassivaTessereService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stampa massiva tessere (f.4): dalla lista tessere admin, con gli stessi
 * filtri (anno, ricerca, stato) o con selezione esplicita (ids[]),
 * produce un unico PDF multi-tessera per la stampa in blocco.
 */
class StampaMassivaTessereController extends Controller
{
    /** Limite difensivo per richiesta (dompdf su PDF molto grandi). */
    public const MAX_TESSERE = 500;

    public function __construct(private readonly StampaMassivaTessereService $stampa) {}

    public function pdf(StampaMassivaTessereRequest $request): Response|RedirectResponse
    {
        $associazione = $this->associazioneCorrente();

        if ($associazione === null) {
            return redirect()->route('dashboard')->with('warning', 'Seleziona prima un\'associazione.');
        }

        $tessere = $this->queryFiltrata($request)
            ->orderBy('soci.cognome')
            ->orderBy('soci.nome')
            ->select('tessere.*')
            ->limit(self::MAX_TESSERE)
            ->get();

        if ($tessere->isEmpty()) {
            return redirect()
                ->route('tessere.index', $request->only(['anno', 'search', 'stato']))
                ->with('warning', 'Nessuna tessera da stampare con i filtri selezionati.');
        }

        return $this->stampa->render($associazione, $tessere);
    }

    /**
     * Stessa semantica dei filtri della lista tessere (anno, ricerca, stato),
     * più la selezione esplicita ids[]. Tenant-scoped dal global scope.
     *
     * @return Builder<Tessera>
     */
    private function queryFiltrata(StampaMassivaTessereRequest $request): Builder
    {
        $query = Tessera::query()
            ->join('soci', 'tessere.socio_id', '=', 'soci.id')
            ->whereNull('soci.deleted_at');

        if ($ids = $request->validated('ids')) {
            return $query->whereIn('tessere.id', $ids);
        }

        $query->where('tessere.anno_validita', (int) ($request->validated('anno') ?? now()->year));

        $search = trim((string) ($request->validated('search') ?? ''));
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

        match ($request->validated('stato')) {
            Tessera::STATO_ATTIVA => $query->valide(),
            Tessera::STATO_SCADUTA => $query->scaduteEffettive(),
            Tessera::STATO_SOSPESA, Tessera::STATO_ANNULLATA => $query->where('tessere.stato', $request->validated('stato')),
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
