<?php

namespace App\Http\Controllers\Operativita;

use App\Http\Controllers\Controller;
use App\Http\Requests\Operativita\StoreAttivitaAdminRequest;
use App\Models\AttivitaAdmin;
use App\Models\Evento;
use App\Models\Socio;
use App\Models\Utente;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Attività/promemoria admin (f.4 del piano "Gestione 2"): pagina elenco
 * con filtri (stato, "le mie"), creazione/modifica/completamento.
 * Il promemoria email del giorno di scadenza è inviato dal comando
 * attivita:promemoria (schedulato in fase Rifiniture).
 */
class AttivitaAdminController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', AttivitaAdmin::class);

        $stato = (string) $request->query('stato', AttivitaAdmin::STATO_APERTA);
        if (! in_array($stato, [...AttivitaAdmin::STATI, 'all'], true)) {
            $stato = AttivitaAdmin::STATO_APERTA;
        }

        $assegnatario = (string) $request->query('assegnatario', 'tutte');

        $attivita = AttivitaAdmin::query()
            ->with(['assegnatario', 'notabile'])
            ->when($stato !== 'all', fn ($q) => $q->where('stato', $stato))
            ->when($assegnatario === 'mie', fn ($q) => $q->where('assegnatario_id', $request->user()->id))
            ->orderByRaw('CASE WHEN scadenza IS NULL THEN 1 ELSE 0 END')
            ->orderBy('scadenza')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $tenantId = AttivitaAdmin::currentAssociazioneId();

        return view('operativita.attivita.index', [
            'attivita' => $attivita,
            'stato' => $stato,
            'assegnatario' => $assegnatario,
            'utenti' => Utente::query()
                ->where('attivo', true)
                ->when($tenantId !== null, fn ($q) => $q->where('associazione_id', $tenantId))
                ->orderBy('nome')
                ->orderBy('cognome')
                ->get(['id', 'nome', 'cognome', 'email']),
            'sociAttivi' => Socio::query()
                ->where('stato', Socio::STATO_ATTIVO)
                ->orderBy('cognome')
                ->orderBy('nome')
                ->get(['id', 'nome', 'cognome', 'numero_socio']),
            'eventi' => Evento::query()
                ->orderByDesc('data_evento')
                ->limit(100)
                ->get(['id', 'titolo', 'data_evento']),
        ]);
    }

    public function store(StoreAttivitaAdminRequest $request): RedirectResponse
    {
        $this->authorize('create', AttivitaAdmin::class);

        AttivitaAdmin::create($request->datiAttivita());

        return redirect()
            ->route('attivita-admin.index')
            ->with('success', 'Attività creata.');
    }

    public function update(StoreAttivitaAdminRequest $request, AttivitaAdmin $attivita): RedirectResponse
    {
        $this->authorize('update', $attivita);

        $attivita->update($request->datiAttivita());

        return redirect()
            ->route('attivita-admin.index')
            ->with('success', 'Attività aggiornata.');
    }

    /**
     * Completa/riapre l'attività (toggle dello stato).
     */
    public function toggle(AttivitaAdmin $attivita): RedirectResponse
    {
        $this->authorize('update', $attivita);

        if ($attivita->isCompletata()) {
            $attivita->update([
                'stato' => AttivitaAdmin::STATO_APERTA,
                'completata_at' => null,
            ]);

            return back()->with('success', 'Attività riaperta.');
        }

        $attivita->update([
            'stato' => AttivitaAdmin::STATO_COMPLETATA,
            'completata_at' => now(),
        ]);

        return back()->with('success', 'Attività completata.');
    }

    public function destroy(AttivitaAdmin $attivita): RedirectResponse
    {
        $this->authorize('delete', $attivita);

        $attivita->delete();

        return redirect()
            ->route('attivita-admin.index')
            ->with('success', 'Attività eliminata.');
    }
}
