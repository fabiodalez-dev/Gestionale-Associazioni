<?php

namespace App\Http\Controllers\Struttura;

use App\Http\Controllers\Controller;
use App\Http\Requests\Struttura\UpdateModuloIscrizioneRequest;
use App\Models\Associazione;
use App\Models\CampoPersonalizzato;
use App\Models\RichiestaIscrizione;
use App\Services\Iscrizioni\FormIscrizioneService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Mews\Purifier\Facades\Purifier;

/**
 * Sezione admin "Modulo di iscrizione" (PIANO-ISCRIZIONI §Rotte/Admin),
 * linkata dalle Configurazioni: apertura iscrizioni socio, modalità
 * (preiscrizione/diretta), testo introduttivo purificato e builder dello
 * schema iscrizione_form_config (campi standard con visibile/obbligatorio/
 * ordine + multiselect dei campi personalizzati del tenant).
 *
 * Autorizzazione: policy viewModuloIscrizione / updateModuloIscrizione
 * (struttura.view / struttura.update — admin_associazione e super_admin
 * col tenant selezionato; operatore escluso).
 */
class ModuloIscrizioneController extends Controller
{
    public function __construct(
        private readonly FormIscrizioneService $formIscrizione,
    ) {}

    public function edit(): View
    {
        $associazione = $this->associazioneCorrente();
        Gate::authorize('viewModuloIscrizione', $associazione);

        $schema = $this->formIscrizione->schema($associazione);

        // Builder: i campi configurati nel loro ordine + gli standard mancanti
        // in coda (non visibili), così la tabella mostra sempre tutti i campi.
        $configurati = collect($schema['campi_standard']);
        $mancanti = collect(FormIscrizioneService::CAMPI_STANDARD)
            ->diff($configurati->pluck('campo'))
            ->values()
            ->map(fn (string $campo) => [
                'campo' => $campo,
                'visibile' => false,
                'obbligatorio' => false,
            ]);

        $config = $associazione->iscrizioni()->iscrizione_form_config ?? [];

        return view('struttura.modulo-iscrizione.edit', [
            'associazione' => $associazione,
            'campiStandard' => $configurati->concat($mancanti)->values(),
            'labels' => FormIscrizioneService::LABELS,
            'introHtml' => (string) ($config['intro_html'] ?? ''),
            'campiPersonalizzati' => CampoPersonalizzato::query()
                ->forAssociazione($associazione->id)
                ->orderBy('ordine')
                ->orderBy('nome_campo')
                ->get(),
            'idSelezionati' => $schema['campi_personalizzati']->pluck('id')->all(),
            // Anteprima del form pubblico (rotta del modulo frontend: /a/{slug}/iscriviti)
            'urlAnteprima' => url('/a/'.$associazione->slug.'/iscriviti'),
            'statistiche' => [
                'invii' => RichiestaIscrizione::query()->where('created_at', '>=', now()->subDays(30))->count(),
                'approvate' => RichiestaIscrizione::query()->where('created_at', '>=', now()->subDays(30))->where('stato', RichiestaIscrizione::STATO_APPROVATA)->count(),
                'in_attesa' => RichiestaIscrizione::query()->inAttesa()->count(),
            ],
        ]);
    }

    public function update(UpdateModuloIscrizioneRequest $request): RedirectResponse
    {
        $associazione = $request->associazioneCorrente();
        $dati = $request->validated();

        $associazione->iscrizioni()->aggiorna([
            'iscrizioni_socio_aperte' => $request->boolean('iscrizioni_socio_aperte'),
            'modalita_iscrizione' => $dati['modalita_iscrizione'],
            'iscrizione_form_config' => [
                'intro_html' => $this->introPurificata($dati),
                'campi_standard' => $this->campiStandardOrdinati($request),
                'campi_personalizzati' => $this->campiPersonalizzatiDelTenant($associazione, $dati),
            ],
        ]);

        return redirect()
            ->route('configurazioni.modulo-iscrizione')
            ->with('success', 'Modulo di iscrizione salvato.');
    }

    /**
     * Intro salvata GIÀ purificata con mews/purifier (difesa in scrittura,
     * oltre alla purificazione in lettura di FormIscrizioneService::schema()).
     *
     * @param  array<string, mixed>  $dati
     */
    private function introPurificata(array $dati): ?string
    {
        $intro = trim((string) ($dati['intro_html'] ?? ''));

        if ($intro === '') {
            return null;
        }

        $pulita = trim((string) Purifier::clean($intro));

        return $pulita === '' ? null : $pulita;
    }

    /**
     * Righe campi standard ordinate per l'input "ordine" del builder
     * (l'ordine nello schema = ordine di render del form pubblico).
     * Un campo non visibile non può essere obbligatorio.
     *
     * @return list<array{campo: string, visibile: bool, obbligatorio: bool}>
     */
    private function campiStandardOrdinati(UpdateModuloIscrizioneRequest $request): array
    {
        return collect(FormIscrizioneService::CAMPI_STANDARD)
            ->map(function (string $campo) use ($request): array {
                $visibile = $request->boolean('campi_standard.'.$campo.'.visibile');

                return [
                    'campo' => $campo,
                    'visibile' => $visibile,
                    'obbligatorio' => $visibile && $request->boolean('campi_standard.'.$campo.'.obbligatorio'),
                    'ordine' => (int) $request->input('campi_standard.'.$campo.'.ordine', 99),
                ];
            })
            ->sortBy([['ordine', 'asc'], ['campo', 'asc']])
            ->values()
            ->map(fn (array $riga) => [
                'campo' => $riga['campo'],
                'visibile' => $riga['visibile'],
                'obbligatorio' => $riga['obbligatorio'],
            ])
            ->all();
    }

    /**
     * Id dei campi personalizzati nell'ordine di invio, riconfermati sul
     * tenant corrente (difesa in profondità oltre alla regola exists).
     *
     * @param  array<string, mixed>  $dati
     * @return list<string>
     */
    private function campiPersonalizzatiDelTenant(Associazione $associazione, array $dati): array
    {
        $ids = array_values(array_filter(
            is_array($dati['campi_personalizzati'] ?? null) ? $dati['campi_personalizzati'] : [],
            'is_string'
        ));

        if ($ids === []) {
            return [];
        }

        $delTenant = CampoPersonalizzato::query()
            ->forAssociazione($associazione->id)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        return array_values(array_intersect($ids, $delTenant));
    }

    private function associazioneCorrente(): Associazione
    {
        return Associazione::findOrFail(session('associazione_id'));
    }
}
