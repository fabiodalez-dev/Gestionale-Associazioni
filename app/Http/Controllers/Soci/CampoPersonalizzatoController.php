<?php

namespace App\Http\Controllers\Soci;

use App\Http\Controllers\Controller;
use App\Http\Requests\Soci\CampoPersonalizzatoRequest;
use App\Models\Associazione;
use App\Models\CampoPersonalizzato;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Configurazione campi personalizzati (spec soci §5) — solo admin.
 * Include i 6 campi preset attivabili in batch (§5.3).
 */
class CampoPersonalizzatoController extends Controller
{
    use AuthorizesRequests;

    /** @var array<string, array{tipo_campo: string, opzioni: ?string}> */
    public const PRESET = [
        'Professione' => ['tipo_campo' => 'text', 'opzioni' => null],
        'Titolo di Studio' => [
            'tipo_campo' => 'select',
            'opzioni' => "Licenza Media\nDiploma\nLaurea Triennale\nLaurea Magistrale\nMaster\nDottorato",
        ],
        'Data Diploma/Laurea' => ['tipo_campo' => 'date', 'opzioni' => null],
        'Contatto di Emergenza' => ['tipo_campo' => 'text', 'opzioni' => null],
        'Specializzazione' => ['tipo_campo' => 'text', 'opzioni' => null],
        'Sito Web' => ['tipo_campo' => 'url', 'opzioni' => null],
    ];

    public function index(): View
    {
        $this->authorize('viewAny', CampoPersonalizzato::class);

        return view('soci.campi.index', [
            'campi' => CampoPersonalizzato::query()->withCount('valori')->orderBy('ordine')->orderBy('nome_campo')->get(),
            'preset' => self::PRESET,
            'tipiCampo' => CampoPersonalizzato::TIPI_CAMPO,
        ]);
    }

    public function store(CampoPersonalizzatoRequest $request): RedirectResponse
    {
        $this->authorize('create', CampoPersonalizzato::class);

        $campo = CampoPersonalizzato::create($request->validated());

        // Creato dal costruttore del modulo di iscrizione: lo aggiunge subito al modulo
        if ($request->boolean('aggiungi_al_modulo')) {
            $associazione = Associazione::query()->find(CampoPersonalizzato::currentAssociazioneId());
            if ($associazione !== null) {
                $config = $associazione->iscrizioni()->iscrizione_form_config ?? [];
                $config['campi_personalizzati'] = array_values(array_unique([...($config['campi_personalizzati'] ?? []), $campo->id]));
                $associazione->iscrizioni()->aggiorna(['iscrizione_form_config' => $config]);
            }

            return redirect()->route('configurazioni.modulo-iscrizione')->with('success', 'Campo "'.$campo->nome_campo.'" aggiunto al modulo.');
        }

        return redirect()->route('config-campi.index')->with('success', 'Campo personalizzato creato.');
    }

    /**
     * Inserimento batch dei campi preset selezionati (duplicati ignorati).
     */
    public function preset(Request $request): RedirectResponse
    {
        $this->authorize('create', CampoPersonalizzato::class);

        $selezionati = array_intersect((array) $request->input('preset', []), array_keys(self::PRESET));
        $aggiunti = 0;

        foreach ($selezionati as $nome) {
            $esiste = CampoPersonalizzato::query()->where('nome_campo', $nome)->exists();

            if ($esiste) {
                continue;
            }

            CampoPersonalizzato::create([
                'nome_campo' => $nome,
                'tipo_campo' => self::PRESET[$nome]['tipo_campo'],
                'opzioni' => self::PRESET[$nome]['opzioni'],
                'obbligatorio' => false,
            ]);
            $aggiunti++;
        }

        return redirect()->route('config-campi.index')->with('success', "{$aggiunti} campi aggiunti.");
    }

    public function update(CampoPersonalizzatoRequest $request, CampoPersonalizzato $campo): RedirectResponse
    {
        $this->authorize('update', $campo);

        $campo->update($request->validated());

        return redirect()->route('config-campi.index')->with('success', 'Campo personalizzato aggiornato.');
    }

    public function destroy(CampoPersonalizzato $campo): RedirectResponse
    {
        $this->authorize('delete', $campo);

        // I valori associati vengono eliminati dalla FK cascade
        $campo->delete();

        return redirect()->route('config-campi.index')->with('success', 'Campo personalizzato eliminato.');
    }
}
