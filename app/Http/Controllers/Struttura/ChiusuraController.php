<?php

namespace App\Http\Controllers\Struttura;

use App\Http\Controllers\Controller;
use App\Models\Associazione;
use App\Models\Chiusura;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Calendario chiusure dell'associazione (#34), nella pagina Configurazioni:
 * giorni o periodi di chiusura, una volta o ogni anno, per tutte le sedi o
 * per una, sospendibili senza cancellarli. Le festività nazionali sono
 * calcolate (CalendarioAperture) e non vanno inserite.
 */
class ChiusuraController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $associazione = $this->associazione();

        $dati = $request->validate([
            'titolo' => ['required', 'string', 'max:150'],
            'data_inizio' => ['required', 'date'],
            'data_fine' => ['nullable', 'date', 'after_or_equal:data_inizio'],
            'sede_id' => ['nullable', 'uuid', Rule::exists('sedi', 'id')->where('associazione_id', $associazione->id)],
            'ogni_anno' => ['nullable', 'boolean'],
        ], [
            'data_fine.after_or_equal' => 'La fine della chiusura non può essere prima dell\'inizio.',
        ]);

        $ogniAnno = (bool) ($dati['ogni_anno'] ?? false);
        $fine = $dati['data_fine'] ?? $dati['data_inizio'];
        // Una chiusura annuale dura al più un anno: oltre chiuderebbe sempre
        if ($ogniAnno && now()->parse($fine)->gte(now()->parse($dati['data_inizio'])->addYear())) {
            return back()->withInput()->withErrors(['data_fine' => 'Una chiusura che si ripete ogni anno deve durare meno di un anno.']);
        }

        Chiusura::query()->create([
            'associazione_id' => $associazione->id,
            'sede_id' => $dati['sede_id'] ?? null,
            'titolo' => $dati['titolo'],
            'data_inizio' => $dati['data_inizio'],
            'data_fine' => $fine,
            'ogni_anno' => $ogniAnno,
            'attiva' => true,
        ]);

        return $this->torna('Chiusura aggiunta.');
    }

    public function toggle(string $chiusura): RedirectResponse
    {
        $trovata = $this->trova($chiusura);
        $trovata->update(['attiva' => ! $trovata->attiva]);

        return $this->torna($trovata->attiva ? 'Chiusura riattivata.' : 'Chiusura sospesa: resta in elenco ma non chiude più nessun giorno.');
    }

    public function destroy(string $chiusura): RedirectResponse
    {
        $this->trova($chiusura)->delete();

        return $this->torna('Chiusura eliminata.');
    }

    private function associazione(): Associazione
    {
        $associazione = Associazione::findOrFail(session('associazione_id'));
        Gate::authorize('manageSettings', $associazione);

        return $associazione;
    }

    private function trova(string $id): Chiusura
    {
        $associazione = $this->associazione();

        return Chiusura::query()->withoutGlobalScopes()->where('associazione_id', $associazione->id)->findOrFail($id);
    }

    private function torna(string $messaggio): RedirectResponse
    {
        return redirect()->route('configurazioni.index', ['section' => 'chiusure'])->with('success', $messaggio);
    }
}
