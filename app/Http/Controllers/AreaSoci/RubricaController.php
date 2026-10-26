<?php

namespace App\Http\Controllers\AreaSoci;

use App\Http\Controllers\Controller;
use App\Models\RubricaContatto;
use App\Models\RubricaScheda;
use App\Models\Socio;
use App\Services\Comunicazione\RubricaNonValidaException;
use App\Services\Comunicazione\RubricaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Rubrica interna nell'area soci (issue #14): la propria scheda (consenso,
 * campi visibili, revoca), la consultazione con i filtri e il contatto
 * mediato. Niente esportazioni: si consulta una pagina alla volta.
 */
class RubricaController extends Controller
{
    public function __construct(
        private readonly RubricaService $rubrica,
    ) {}

    public function index(Request $request): View
    {
        /** @var Socio $socio */
        $socio = $request->user('socio');
        $blocco = $this->rubrica->motivoBloccoConsultazione($socio);

        return view('area-soci.rubrica.index', [
            'socio' => $socio,
            'scheda' => $this->rubrica->scheda($socio),
            'esclusione' => $this->rubrica->motivoEsclusione($socio),
            'blocco' => $blocco,
            'schede' => $blocco === null ? $this->rubrica->cerca($request->only(['nome', 'citta', 'interesse'])) : null,
            'filtri' => $request->only(['nome', 'citta', 'interesse']),
            'campi' => array_intersect_key(RubricaScheda::CAMPI, array_flip($this->rubrica->impostazioni($socio)->rubrica_campi)),
        ]);
    }

    public function salva(Request $request): RedirectResponse
    {
        $dati = $request->validate([
            'consenso' => ['accepted'],
            'campi' => ['nullable', 'array'],
            'campi.*' => [Rule::in(array_keys(RubricaScheda::CAMPI))],
            'presentazione' => ['nullable', 'string', 'max:1000'],
            'interessi' => ['nullable', 'string', 'max:500'],
        ], ['consenso.accepted' => 'Per comparire in rubrica serve il tuo consenso.']);

        try {
            $this->rubrica->salva(
                $request->user('socio'),
                $dati['campi'] ?? [],
                $dati['presentazione'] ?? null,
                explode(',', (string) ($dati['interessi'] ?? '')),
                $request->ip(),
            );
        } catch (RubricaNonValidaException $e) {
            return redirect()->route('area-soci.rubrica')->with('error', $e->getMessage());
        }

        return redirect()->route('area-soci.rubrica')->with('success', 'La tua scheda in rubrica è aggiornata.');
    }

    public function revoca(Request $request): RedirectResponse
    {
        $this->rubrica->revoca($request->user('socio'), $request->ip());

        return redirect()->route('area-soci.rubrica')->with('success', 'Sei uscito dalla rubrica: gli altri soci non vedono più la tua scheda.');
    }

    public function contatta(Request $request, RubricaScheda $scheda): RedirectResponse
    {
        $dati = $request->validate([
            'oggetto' => ['required', 'string', 'max:150'],
            'testo' => ['required', 'string', 'max:3000'],
        ]);

        try {
            $this->rubrica->contatta($request->user('socio'), $scheda, $dati['oggetto'], $dati['testo']);
        } catch (RubricaNonValidaException $e) {
            return redirect()->route('area-soci.rubrica')->with('error', $e->getMessage());
        }

        return redirect()->route('area-soci.rubrica')->with('success', 'Messaggio inviato. Se risponde, riceverai la risposta alla tua email.');
    }

    public function confermaSegnalazione(Request $request, RubricaContatto $contatto): View
    {
        abort_unless($contatto->destinatario_socio_id === $request->user('socio')->id, 404);

        $contatto->load('mittente:id,nome,cognome');

        return view('area-soci.rubrica.segnala', ['contatto' => $contatto]);
    }

    public function segnala(Request $request, RubricaContatto $contatto): RedirectResponse
    {
        try {
            $this->rubrica->segnala($contatto, $request->user('socio'));
        } catch (RubricaNonValidaException $e) {
            return redirect()->route('area-soci.rubrica')->with('error', $e->getMessage());
        }

        return redirect()->route('area-soci.rubrica')->with('success', 'Grazie: la segreteria valuterà la segnalazione.');
    }
}
