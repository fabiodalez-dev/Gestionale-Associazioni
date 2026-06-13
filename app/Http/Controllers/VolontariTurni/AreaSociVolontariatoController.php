<?php

namespace App\Http\Controllers\VolontariTurni;

use App\Http\Controllers\Controller;
use App\Http\Requests\VolontariTurni\AggiornaDisponibilitaSocioRequest;
use App\Models\Socio;
use App\Models\Turno;
use App\Models\TurnoAssegnazione;
use App\Services\VolontariTurni\CandidaturaService;
use App\Services\VolontariTurni\Disponibilita;
use App\Services\VolontariTurni\RegistroOreService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Volontariato nell'area soci (f.1, f.4, f.5 del piano "Organizzazione 3"):
 * il socio gestisce la propria disponibilità, si auto-candida ai turni
 * aperti compatibili, rinuncia con motivo e consulta le ore registrate.
 */
class AreaSociVolontariatoController extends Controller
{
    public function __construct(
        private readonly CandidaturaService $candidature,
        private readonly RegistroOreService $registro,
    ) {}

    public function index(Request $request): View
    {
        /** @var Socio $socio */
        $socio = $request->user('socio');

        return view('area-soci.volontariato.index', $this->datiPagina($socio));
    }

    /**
     * Il socio aggiorna disponibilità e flag volontario (f.1).
     */
    public function aggiornaDisponibilita(AggiornaDisponibilitaSocioRequest $request): RedirectResponse
    {
        /** @var Socio $socio */
        $socio = $request->user('socio');

        $socio->update([
            'volontario_attivo' => $request->boolean('volontario_attivo'),
            'disponibilita' => $request->disponibilitaNormalizzata(),
        ]);

        return redirect()
            ->route('area-soci.volontariato')
            ->with('success', 'Disponibilità aggiornata.');
    }

    /**
     * Auto-candidatura a un turno aperto (f.4).
     */
    public function candidati(Request $request, Turno $turno): RedirectResponse
    {
        /** @var Socio $socio */
        $socio = $request->user('socio');

        try {
            $this->candidature->candidati($turno, $socio);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return redirect()
            ->route('area-soci.volontariato')
            ->with('success', $turno->auto_conferma
                ? 'Candidatura confermata. Grazie!'
                : 'Candidatura inviata: attendi la conferma.');
    }

    /**
     * Rinuncia a un turno con motivo: lo slot torna disponibile (f.4).
     */
    public function rinuncia(Request $request, TurnoAssegnazione $assegnazione): RedirectResponse
    {
        /** @var Socio $socio */
        $socio = $request->user('socio');

        abort_unless($assegnazione->socio_id === $socio->id, 403);

        $request->validate(['motivo' => ['nullable', 'string', 'max:500']]);

        $this->candidature->rinuncia($assegnazione, $request->input('motivo'));

        return redirect()
            ->route('area-soci.volontariato')
            ->with('success', 'Hai rinunciato al turno.');
    }

    /**
     * @return array<string, mixed>
     */
    private function datiPagina(Socio $socio): array
    {
        $mieAssegnazioni = TurnoAssegnazione::query()
            ->with('turno.mansione')
            ->where('socio_id', $socio->id)
            ->whereIn('stato', [TurnoAssegnazione::STATO_PROPOSTO, TurnoAssegnazione::STATO_CONFERMATO])
            ->get()
            ->filter(fn (TurnoAssegnazione $a) => $a->turno !== null
                && ! str_starts_with((string) $a->turno->titolo, RegistroOreService::PREFISSO_MANUALE))
            ->sortBy(fn (TurnoAssegnazione $a) => $a->turno?->data?->toDateString())
            ->values();

        $turniGiaMiei = $mieAssegnazioni->pluck('turno_id')->all();

        // Turni aperti futuri con posti, escludendo quelli già miei.
        $turniAperti = Turno::query()
            ->with(['mansione:id,nome', 'spazio:id,nome', 'assegnazioni:id,turno_id,stato'])
            ->where('stato', Turno::STATO_APERTO)
            ->whereDate('data', '>=', today())
            ->where('titolo', 'not like', RegistroOreService::PREFISSO_MANUALE.'%')
            ->whereNotIn('id', $turniGiaMiei)
            ->orderBy('data')
            ->orderBy('ora_inizio')
            ->get()
            ->filter(fn (Turno $t) => $t->postiDisponibili() > 0)
            ->values();

        return [
            'socio' => $socio,
            'turniAperti' => $turniAperti,
            'mieAssegnazioni' => $mieAssegnazioni,
            'oreTotali' => $this->registro->totaleOreSocio($socio),
            'oreAnno' => $this->registro->totaleOreSocio($socio, (int) now()->year),
            'giorni' => Disponibilita::GIORNI,
            'fasce' => Disponibilita::FASCE,
        ];
    }
}
