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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

        $spazioId = (string) $request->validated('spazio_id');
        $dal = $request->date('dal');
        $al = $request->date('al');

        // Ricontrollo dell'overlap DENTRO la transazione con lock applicativo
        // sulla riga spazio: serializza le richieste concorrenti per lo stesso
        // spazio così che due prenotazioni sovrapposte non passino entrambe
        // (la validazione del Form Request è solo un check-then-act non atomico).
        DB::transaction(function () use ($request, $spazioId, $dal, $al): void {
            $this->lockSpazio($spazioId);
            $this->assertNessunConflitto($spazioId, $dal, $al);

            PrenotazioneSpazio::create([
                'spazio_id' => $spazioId,
                'titolo' => $request->validated('titolo'),
                'dal' => $dal,
                'al' => $al,
                'note' => $request->validated('note'),
                'richiedente_id' => $request->user()?->id,
            ]);
        });

        return redirect()
            ->route('spazi.prenotazioni.index', ['spazio' => $spazioId])
            ->with('success', 'Prenotazione registrata con successo.');
    }

    public function update(PrenotazioneSpazioRequest $request, PrenotazioneSpazio $prenotazione): RedirectResponse
    {
        $this->authorize('update', $prenotazione);

        $validated = $request->validated();
        $spazioId = (string) $validated['spazio_id'];
        $dal = $request->date('dal');
        $al = $request->date('al');

        // Stesso ricontrollo atomico dello store, escludendo la prenotazione
        // corrente dal confronto (un edit non va in conflitto con sé stessa).
        DB::transaction(function () use ($prenotazione, $validated, $spazioId, $dal, $al): void {
            $this->lockSpazio($spazioId);
            $this->assertNessunConflitto($spazioId, $dal, $al, $prenotazione->id);

            $prenotazione->update($validated);
        });

        return redirect()
            ->route('spazi.prenotazioni.index', ['spazio' => $prenotazione->spazio_id])
            ->with('success', 'Prenotazione aggiornata con successo.');
    }

    /**
     * Lock applicativo sulla riga spazio (mutex per-spazio). Su sqlite (test)
     * lockForUpdate è un no-op, ma la transazione serializza comunque le
     * scritture; su MySQL (produzione) il lock impedisce la race di overlap.
     */
    private function lockSpazio(string $spazioId): void
    {
        // Su sqlite (test) lockForUpdate è un no-op: la riga viene comunque
        // riletta come punto di sincronizzazione esplicito della transazione.
        Spazio::query()
            ->whereKey($spazioId)
            ->when(
                DB::connection()->getDriverName() !== 'sqlite',
                fn ($q) => $q->lockForUpdate()
            )
            ->first();
    }

    /**
     * Rilancia l'errore di conflitto overlap (stesso messaggio del Form
     * Request) se un'altra prenotazione si sovrappone all'intervallo: usato
     * DENTRO la transazione, dopo il lock, come barriera anti-race.
     *
     * @throws ValidationException
     */
    private function assertNessunConflitto(
        string $spazioId,
        ?Carbon $dal,
        ?Carbon $al,
        ?string $ignoraId = null
    ): void {
        if ($dal === null || $al === null) {
            return;
        }

        $conflitto = PrenotazioneSpazio::query()
            ->inConflittoCon($spazioId, $dal, $al, $ignoraId)
            ->orderBy('dal')
            ->first();

        if ($conflitto !== null) {
            throw ValidationException::withMessages([
                'dal' => sprintf(
                    'Lo spazio è già prenotato per "%s" dal %s al %s. Scegli un altro orario.',
                    $conflitto->titolo,
                    $conflitto->dal->format('d/m/Y H:i'),
                    $conflitto->al->format('d/m/Y H:i'),
                ),
            ]);
        }
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
