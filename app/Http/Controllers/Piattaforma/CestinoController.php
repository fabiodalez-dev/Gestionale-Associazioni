<?php

namespace App\Http\Controllers\Piattaforma;

use App\Http\Controllers\Controller;
use App\Models\Documento;
use App\Models\Evento;
use App\Models\Socio;
use App\Models\Utente;
use App\Models\Verbale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Cestino unificato (f.24 del piano "Gestione 2"): un'unica pagina con i
 * soft-deleted di soci/eventi/documenti/verbali, filtro per tipo, ripristino
 * ed eliminazione definitiva con i permessi del modulo di appartenenza
 * (Policy esistenti: restore/forceDelete; per gli eventi l'abilità è
 * 'delete', la EventoPolicy non definisce restore/forceDelete).
 *
 * L'isolamento tenant è garantito dal global scope BelongsToAssociazione:
 * un id di un altro tenant produce 404 sul findOrFail.
 */
class CestinoController extends Controller
{
    use AuthorizesRequests;

    private const PER_PAGINA = 25;

    /**
     * Tipi gestiti: model, permesso di visualizzazione e abilità Policy
     * per ripristino/eliminazione definitiva.
     *
     * @var array<string, array{model: class-string<Socio|Evento|Documento|Verbale>, view: string, ability_restore: string, ability_force: string, etichetta: string, icona: string}>
     */
    public const TIPI = [
        'soci' => [
            'model' => Socio::class,
            'view' => 'soci.view',
            'ability_restore' => 'restore',
            'ability_force' => 'forceDelete',
            'etichetta' => 'Socio',
            'icona' => 'bi-person',
        ],
        'eventi' => [
            'model' => Evento::class,
            'view' => 'eventi.view',
            'ability_restore' => 'delete',
            'ability_force' => 'delete',
            'etichetta' => 'Evento',
            'icona' => 'bi-calendar-event',
        ],
        'documenti' => [
            'model' => Documento::class,
            'view' => 'documenti.view',
            'ability_restore' => 'restore',
            'ability_force' => 'forceDelete',
            'etichetta' => 'Documento',
            'icona' => 'bi-file-earmark',
        ],
        'verbali' => [
            'model' => Verbale::class,
            'view' => 'verbali.view',
            'ability_restore' => 'restore',
            'ability_force' => 'forceDelete',
            'etichetta' => 'Verbale',
            'icona' => 'bi-journal-text',
        ],
    ];

    public function index(Request $request): View
    {
        /** @var Utente $utente */
        $utente = $request->user();

        $tipiVisibili = $this->tipiVisibili($utente);

        abort_if($tipiVisibili === [], 403);

        $tipoFiltro = (string) $request->query('tipo', '');
        if ($tipoFiltro !== '' && ! array_key_exists($tipoFiltro, self::TIPI)) {
            $tipoFiltro = '';
        }

        $tipiDaElencare = $tipoFiltro !== '' && in_array($tipoFiltro, $tipiVisibili, true)
            ? [$tipoFiltro]
            : ($tipoFiltro !== '' ? [] : $tipiVisibili);

        /** @var Collection<int, array<string, mixed>> $elementi */
        $elementi = collect();

        foreach ($tipiDaElencare as $tipo) {
            $config = self::TIPI[$tipo];
            $model = $config['model'];

            $model::onlyTrashed()
                ->orderByDesc('deleted_at')
                ->limit(500)
                ->get()
                ->each(function (Model $record) use (&$elementi, $tipo, $config): void {
                    $elementi->push([
                        'tipo' => $tipo,
                        'config' => $config,
                        'record' => $record,
                        'etichetta' => $this->etichetta($tipo, $record),
                        'dettaglio' => $this->dettaglio($tipo, $record),
                        'eliminato_il' => $record->getAttribute('deleted_at'),
                    ]);
                });
        }

        $ordinati = $elementi->sortByDesc('eliminato_il')->values();

        $pagina = LengthAwarePaginator::resolveCurrentPage();
        $paginati = new LengthAwarePaginator(
            $ordinati->forPage($pagina, self::PER_PAGINA)->values(),
            $ordinati->count(),
            self::PER_PAGINA,
            $pagina,
            ['path' => LengthAwarePaginator::resolveCurrentPath()]
        );
        $paginati->appends($request->query());

        return view('piattaforma.cestino.index', [
            'elementi' => $paginati,
            'tipiVisibili' => $tipiVisibili,
            'tipoFiltro' => $tipoFiltro,
            'tipi' => self::TIPI,
        ]);
    }

    public function ripristina(Request $request, string $tipo, string $id): RedirectResponse
    {
        $config = self::TIPI[$tipo] ?? abort(404);

        $record = $config['model']::onlyTrashed()->findOrFail($id);

        $this->authorize($config['ability_restore'], $record);

        $record->restore();

        return redirect()
            ->route('cestino.index', array_filter(['tipo' => $request->query('tipo')]))
            ->with('success', "{$config['etichetta']} ripristinato con successo.");
    }

    public function eliminaDefinitivamente(Request $request, string $tipo, string $id): RedirectResponse
    {
        $config = self::TIPI[$tipo] ?? abort(404);

        $record = $config['model']::onlyTrashed()->findOrFail($id);

        $this->authorize($config['ability_force'], $record);

        $record->forceDelete();

        return redirect()
            ->route('cestino.index', array_filter(['tipo' => $request->query('tipo')]))
            ->with('success', "{$config['etichetta']} eliminato definitivamente.");
    }

    /**
     * Tipi che l'utente può vedere: abilità viewAny della Policy del modulo
     * (permesso spatie <modulo>.view con fallback per ruolo, come il resto
     * dell'app quando i permessi non sono seedati).
     *
     * @return list<string>
     */
    private function tipiVisibili(Utente $utente): array
    {
        return array_values(array_filter(
            array_keys(self::TIPI),
            fn (string $tipo): bool => $utente->can('viewAny', self::TIPI[$tipo]['model'])
        ));
    }

    private function etichetta(string $tipo, Model $record): string
    {
        return match ($tipo) {
            'soci' => trim(($record->getAttribute('nome') ?? '').' '.($record->getAttribute('cognome') ?? '')) ?: 'Socio',
            'eventi' => (string) ($record->getAttribute('titolo') ?? 'Evento'),
            'documenti' => (string) ($record->getAttribute('nome_file') ?? 'Documento'),
            'verbali' => (string) ($record->getAttribute('titolo') ?? 'Verbale'),
            default => $tipo,
        };
    }

    private function dettaglio(string $tipo, Model $record): ?string
    {
        return match ($tipo) {
            'soci' => $record->getAttribute('email'),
            'eventi' => $record->getAttribute('data_evento')?->format('d/m/Y H:i'),
            'documenti' => $record->getAttribute('categoria'),
            'verbali' => $record->getAttribute('numero') !== null
                ? 'n. '.$record->getAttribute('numero')
                : null,
            default => null,
        };
    }
}
