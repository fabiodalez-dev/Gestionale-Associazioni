<?php

namespace App\Http\Controllers\SpaziRisorse;

use App\Http\Controllers\Controller;
use App\Http\Requests\SpaziRisorse\PrenotazioneSpazioRequest;
use App\Models\PrenotazioneSpazio;
use App\Models\Spazio;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Prenotazioni interne degli spazi (f.8 piano "Organizzazione 3"):
 * lista + vista calendario settimanale per spazio, con rilevazione
 * conflitti di overlap (gestita nel Form Request).
 *
 * Isolamento tenant garantito dal global scope BelongsToAssociazione.
 */
class PrenotazioneSpazioController extends Controller
{
    use AuthorizesRequests;

    /**
     * Lista prenotazioni + calendario settimanale dello spazio selezionato.
     *
     * Query string:
     * - spazio: id dello spazio da mostrare nel calendario (default: primo);
     * - settimana: data (Y-m-d) interna alla settimana da visualizzare.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', PrenotazioneSpazio::class);

        $spazi = Spazio::query()->orderBy('nome')->get();

        // Spazio selezionato per il calendario (id valido del tenant o primo)
        $spazioSelezionato = null;
        if ($request->filled('spazio')) {
            $spazioSelezionato = $spazi->firstWhere('id', $request->query('spazio'));
        }
        $spazioSelezionato ??= $spazi->first();

        // Settimana visualizzata (lunedì → domenica)
        $riferimento = $this->dataRiferimento($request->query('settimana'));
        $inizioSettimana = $riferimento->copy()->startOfWeek(Carbon::MONDAY);
        $fineSettimana = $riferimento->copy()->endOfWeek(Carbon::SUNDAY);

        // Prenotazioni della settimana per lo spazio selezionato (overlap finestra)
        $prenotazioniSettimana = collect();
        if ($spazioSelezionato !== null) {
            $prenotazioniSettimana = PrenotazioneSpazio::query()
                ->where('spazio_id', $spazioSelezionato->id)
                ->where('dal', '<', $fineSettimana)
                ->where('al', '>', $inizioSettimana)
                ->with('richiedente')
                ->orderBy('dal')
                ->get();
        }

        // Elenco completo (prossime e in corso) per la tabella sotto il calendario
        $editPrenotazione = null;
        if ($request->filled('edit')) {
            $editPrenotazione = PrenotazioneSpazio::query()->find($request->query('edit'));
        }

        $prossime = PrenotazioneSpazio::query()
            ->with(['spazio', 'richiedente'])
            ->where('al', '>=', now())
            ->orderBy('dal')
            ->paginate(25)
            ->withQueryString();

        return view('spazi-risorse.prenotazioni.index', [
            'spazi' => $spazi,
            'spazioSelezionato' => $spazioSelezionato,
            'inizioSettimana' => $inizioSettimana,
            'fineSettimana' => $fineSettimana,
            'giorniSettimana' => $this->giorniSettimana($inizioSettimana),
            'prenotazioniPerGiorno' => $this->raggruppaPerGiorno($prenotazioniSettimana),
            'prossime' => $prossime,
            'editPrenotazione' => $editPrenotazione,
        ]);
    }

    public function store(PrenotazioneSpazioRequest $request): RedirectResponse
    {
        $this->authorize('create', PrenotazioneSpazio::class);

        PrenotazioneSpazio::create([
            'spazio_id' => $request->validated('spazio_id'),
            'titolo' => $request->validated('titolo'),
            'dal' => $request->validated('dal'),
            'al' => $request->validated('al'),
            'note' => $request->validated('note'),
            'richiedente_id' => $request->user()?->id,
        ]);

        return redirect()
            ->route('spazi.prenotazioni.index', ['spazio' => $request->input('spazio_id')])
            ->with('success', 'Prenotazione registrata con successo.');
    }

    public function update(PrenotazioneSpazioRequest $request, PrenotazioneSpazio $prenotazione): RedirectResponse
    {
        $this->authorize('update', $prenotazione);

        $prenotazione->update($request->validated());

        return redirect()
            ->route('spazi.prenotazioni.index', ['spazio' => $prenotazione->spazio_id])
            ->with('success', 'Prenotazione aggiornata con successo.');
    }

    public function destroy(PrenotazioneSpazio $prenotazione): RedirectResponse
    {
        $this->authorize('delete', $prenotazione);

        $spazioId = $prenotazione->spazio_id;
        $prenotazione->delete();

        return redirect()
            ->route('spazi.prenotazioni.index', ['spazio' => $spazioId])
            ->with('success', 'Prenotazione eliminata con successo.');
    }

    /**
     * Data interna alla settimana da visualizzare (oggi se assente/invalida).
     */
    private function dataRiferimento(mixed $settimana): Carbon
    {
        if (is_string($settimana) && $settimana !== '') {
            try {
                return Carbon::parse($settimana);
            } catch (\Throwable) {
                // valore non valido → oggi
            }
        }

        return Carbon::today();
    }

    /**
     * I 7 giorni della settimana (lunedì → domenica).
     *
     * @return list<Carbon>
     */
    private function giorniSettimana(Carbon $inizioSettimana): array
    {
        $giorni = [];
        for ($i = 0; $i < 7; $i++) {
            $giorni[] = $inizioSettimana->copy()->addDays($i);
        }

        return $giorni;
    }

    /**
     * Raggruppa le prenotazioni per giorno (chiave Y-m-d) per il calendario.
     *
     * @param  Collection<int, PrenotazioneSpazio>  $prenotazioni
     * @return Collection<string, Collection<int, PrenotazioneSpazio>>
     */
    private function raggruppaPerGiorno(Collection $prenotazioni): Collection
    {
        return $prenotazioni->groupBy(fn (PrenotazioneSpazio $p) => $p->dal->format('Y-m-d'));
    }
}
