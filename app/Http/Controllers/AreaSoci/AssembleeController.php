<?php

namespace App\Http\Controllers\AreaSoci;

use App\Http\Controllers\Controller;
use App\Models\DelegaRiunione;
use App\Models\Riunione;
use App\Models\RiunionePartecipante;
use App\Models\Socio;
use App\Services\Struttura\DelegaNonValidaException;
use App\Services\Struttura\DelegheService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Assemblee nell'area soci (issues #6 e #7): le assemblee in cui il socio
 * è tra gli aventi diritto, con la possibilità di delegare un altro socio
 * e di revocare la delega fino all'ora della prima convocazione.
 *
 * Il delegato si indica con numero socio e cognome: l'area soci non mostra
 * l'elenco degli altri soci.
 */
class AssembleeController extends Controller
{
    public function __construct(
        private readonly DelegheService $deleghe,
    ) {}

    public function index(Request $request): View
    {
        /** @var Socio $socio */
        $socio = $request->user('socio');

        $partecipazioni = RiunionePartecipante::query()
            ->where('socio_id', $socio->id)
            ->whereHas('riunione', fn ($q) => $q
                ->whereIn('organo', [Riunione::ORGANO_ASSEMBLEA_ORDINARIA, Riunione::ORGANO_ASSEMBLEA_STRAORDINARIA])
                ->where('stato', '!=', Riunione::STATO_ANNULLATA)
                ->where('data_ora', '>=', now()->subDays(30)))
            ->with('riunione.spazio')
            ->get()
            ->sortBy(fn (RiunionePartecipante $p) => $p->riunione->data_ora)
            ->values();

        $riunioniIds = $partecipazioni->pluck('riunione_id');

        $deleghe = DelegaRiunione::query()
            ->whereIn('riunione_id', $riunioniIds)
            ->where(fn ($q) => $q->where('delegante_socio_id', $socio->id)->orWhere('delegato_socio_id', $socio->id))
            ->with(['delegante:id,nome,cognome', 'delegato:id,nome,cognome'])
            ->get();

        return view('area-soci.assemblee', [
            'socio' => $socio,
            'assemblee' => $partecipazioni->map(fn (RiunionePartecipante $p) => [
                'riunione' => $p->riunione,
                'partecipante' => $p,
                'delegaData' => $deleghe->first(fn (DelegaRiunione $d) => $d->riunione_id === $p->riunione_id && $d->delegante_socio_id === $socio->id),
                'delegheRicevute' => $deleghe->filter(fn (DelegaRiunione $d) => $d->riunione_id === $p->riunione_id && $d->delegato_socio_id === $socio->id)->values(),
            ]),
        ]);
    }

    public function delega(Request $request, Riunione $riunione): RedirectResponse
    {
        /** @var Socio $socio */
        $socio = $request->user('socio');

        $dati = $request->validate([
            'numero_socio' => ['required', 'string', 'max:50'],
            'cognome' => ['required', 'string', 'max:100'],
        ]);

        // Numero socio e cognome devono corrispondere: senza il cognome
        // basterebbe provare i numeri per scoprire chi è avente diritto
        $delegato = Socio::query()
            ->withoutGlobalScope('sede')
            ->where('numero_socio', trim($dati['numero_socio']))
            ->get()
            ->first(fn (Socio $s) => mb_strtolower(trim((string) $s->cognome)) === mb_strtolower(trim($dati['cognome'])));

        if ($delegato === null) {
            return $this->indietro('error', 'Nessun socio corrisponde a numero socio e cognome indicati.');
        }

        try {
            $this->deleghe->registra($riunione, $socio, $delegato, DelegaRiunione::ORIGINE_AREA_SOCI);
        } catch (DelegaNonValidaException $e) {
            return $this->indietro('error', $e->getMessage());
        }

        return $this->indietro('success', "Delega a {$delegato->nome_completo} registrata. Ti abbiamo inviato una conferma via email.");
    }

    public function revoca(Request $request, Riunione $riunione): RedirectResponse
    {
        /** @var Socio $socio */
        $socio = $request->user('socio');

        $delega = DelegaRiunione::query()
            ->where('riunione_id', $riunione->id)
            ->where('delegante_socio_id', $socio->id)
            ->first();

        if ($delega === null) {
            return $this->indietro('warning', 'Non hai deleghe da revocare per questa assemblea.');
        }

        try {
            $this->deleghe->revoca($delega, DelegaRiunione::ORIGINE_AREA_SOCI);
        } catch (DelegaNonValidaException $e) {
            return $this->indietro('error', $e->getMessage());
        }

        return $this->indietro('success', 'Delega revocata.');
    }

    private function indietro(string $tipo, string $messaggio): RedirectResponse
    {
        return redirect()->route('area-soci.assemblee')->with($tipo, $messaggio);
    }
}
