<?php

namespace App\Http\Controllers\Struttura;

use App\Http\Controllers\Controller;
use App\Http\Requests\Struttura\SalvaAdempimentoRequest;
use App\Models\Adempimento;
use App\Models\AdempimentoAnno;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Adempimenti ricorrenti del tenant (f.20 del piano "Organizzazione 3"):
 * - CRUD dell'anagrafica (titolo, mese 1-12, attivo);
 * - seed iniziale suggerito (assicurazione volontari, bilancio, rinnovo
 *   cariche...) da Adempimento::SUGGERITI;
 * - checklist per anno (adempimenti_anni) renderizzata DENTRO la pagina
 *   Scadenzario esistente; qui le azioni di spunta/de-spunta (toggle).
 *
 * Governance: AdempimentoPolicy (anagrafica → struttura.*; checklist →
 * scadenze.update, condiviso con l'operatore).
 */
class AdempimentoController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Adempimento::class);

        $adempimenti = Adempimento::query()
            ->orderBy('mese_scadenza')
            ->orderBy('titolo')
            ->get();

        $adempimentoInModifica = null;
        if ($request->filled('edit')) {
            $adempimentoInModifica = Adempimento::query()->find($request->query('edit'));
        }

        return view('struttura.adempimenti.index', [
            'adempimenti' => $adempimenti,
            'adempimentoInModifica' => $adempimentoInModifica,
            'suggeriti' => Adempimento::SUGGERITI,
            'esistonoAdempimenti' => $adempimenti->isNotEmpty(),
        ]);
    }

    public function store(SalvaAdempimentoRequest $request): RedirectResponse
    {
        Adempimento::create($request->validated());

        return redirect()->route('adempimenti.index')->with('success', 'Adempimento aggiunto con successo!');
    }

    public function update(SalvaAdempimentoRequest $request, Adempimento $adempimento): RedirectResponse
    {
        $adempimento->update($request->validated());

        return redirect()->route('adempimenti.index')->with('success', 'Adempimento aggiornato con successo!');
    }

    public function destroy(Adempimento $adempimento): RedirectResponse
    {
        $this->authorize('delete', $adempimento);

        $adempimento->delete();

        return redirect()->route('adempimenti.index')->with('success', 'Adempimento eliminato con successo!');
    }

    /**
     * Crea gli adempimenti suggeriti mancanti (seed iniziale, f.20).
     * Idempotente: salta quelli con lo stesso titolo già presenti.
     */
    public function seed(Request $request): RedirectResponse
    {
        $this->authorize('create', Adempimento::class);

        $associazioneId = session('associazione_id') ?? $request->user()?->associazione_id;
        $esistenti = Adempimento::query()->pluck('titolo')->map(fn ($t) => mb_strtolower($t))->all();
        $creati = 0;

        foreach (Adempimento::SUGGERITI as $suggerito) {
            if (in_array(mb_strtolower($suggerito['titolo']), $esistenti, true)) {
                continue;
            }

            Adempimento::create([
                'associazione_id' => $associazioneId,
                'titolo' => $suggerito['titolo'],
                'mese_scadenza' => $suggerito['mese_scadenza'],
                'attivo' => true,
            ]);
            $creati++;
        }

        $messaggio = $creati > 0
            ? "Aggiunti {$creati} adempimenti suggeriti."
            : 'Tutti gli adempimenti suggeriti sono già presenti.';

        return redirect()->route('adempimenti.index')->with('success', $messaggio);
    }

    /**
     * Spunta/de-spunta la voce di checklist per (adempimento, anno) — f.20.
     * Chiamata dalla pagina Scadenzario; torna indietro alla pagina chiamante.
     */
    public function toggleAnno(Request $request, Adempimento $adempimento): RedirectResponse
    {
        $this->authorize('toggleChecklist', $adempimento);

        $validated = $request->validate([
            'anno' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);
        $anno = (int) $validated['anno'];

        $riga = AdempimentoAnno::query()
            ->where('adempimento_id', $adempimento->id)
            ->where('anno', $anno)
            ->first();

        if ($riga === null) {
            AdempimentoAnno::create([
                'associazione_id' => $adempimento->associazione_id,
                'adempimento_id' => $adempimento->id,
                'anno' => $anno,
                'completato_at' => now(),
                'completato_da' => $request->user()?->id,
            ]);
            $messaggio = 'Adempimento segnato come completato.';
        } elseif ($riga->completato_at === null) {
            $riga->update([
                'completato_at' => now(),
                'completato_da' => $request->user()?->id,
            ]);
            $messaggio = 'Adempimento segnato come completato.';
        } else {
            $riga->update([
                'completato_at' => null,
                'completato_da' => null,
            ]);
            $messaggio = 'Adempimento segnato come da fare.';
        }

        return redirect()->back()->with('success', $messaggio);
    }
}
