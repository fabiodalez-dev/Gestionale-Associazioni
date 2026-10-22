<?php

namespace App\Http\Controllers\Piattaforma;

use App\Http\Controllers\Controller;
use App\Models\RichiestaRegistrazione;
use App\Models\Utente;
use App\Services\Piattaforma\RegistrazioneAssociazioni;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Richieste di iscrizione delle associazioni: le vede e le gestisce solo il super admin.
 */
class RichiesteRegistrazioneController extends Controller
{
    public function index(Request $request): View
    {
        $this->soloSuperAdmin($request);

        $stato = in_array($request->query('stato'), ['in_attesa', 'da_confermare', 'approvata', 'rifiutata', 'tutte'], true) ? $request->query('stato') : 'in_attesa';

        $conteggi = RichiestaRegistrazione::query()->whereIn('stato', ['approvata', 'rifiutata'])->selectRaw('stato, count(*) as n')->groupBy('stato')->pluck('n', 'stato');
        $conteggi['in_attesa'] = RichiestaRegistrazione::query()->daEsaminare()->count();
        $conteggi['da_confermare'] = RichiestaRegistrazione::query()->inAttesa()->whereNull('email_verificata_at')->count();

        return view('piattaforma.registrazione.index', [
            'richieste' => RichiestaRegistrazione::query()
                ->with(['associazione:id,nome', 'gestore:id,nome,cognome'])
                ->when($stato === 'in_attesa', fn ($q) => $q->daEsaminare())
                ->when($stato === 'da_confermare', fn ($q) => $q->inAttesa()->whereNull('email_verificata_at'))
                ->when(in_array($stato, ['approvata', 'rifiutata'], true), fn ($q) => $q->where('stato', $stato))
                ->latest()
                ->paginate(20)
                ->withQueryString(),
            'stato' => $stato,
            'conteggi' => $conteggi,
            'modalita' => RichiestaRegistrazione::modalita(),
        ]);
    }

    public function approva(Request $request, RichiestaRegistrazione $richiesta, RegistrazioneAssociazioni $registrazioni): RedirectResponse
    {
        $superAdmin = $this->soloSuperAdmin($request);

        if (! $richiesta->isInAttesa()) {
            return back()->with('warning', 'La richiesta è già stata gestita.');
        }
        if (! $richiesta->isVerificata()) {
            return back()->with('warning', 'L\'email dell\'amministratore non è ancora stata confermata.');
        }
        if ($doppione = $registrazioni->doppione($richiesta)) {
            return back()->with('warning', 'Esiste già un\'associazione con '.$doppione.': controlla prima di approvare.');
        }

        $associazione = $registrazioni->approva($richiesta, $superAdmin);

        return redirect()->route('richieste-registrazione.index')
            ->with('success', $associazione->nome.' è stata creata con il suo amministratore ('.$richiesta->admin_email.').');
    }

    public function rifiuta(Request $request, RichiestaRegistrazione $richiesta, RegistrazioneAssociazioni $registrazioni): RedirectResponse
    {
        $superAdmin = $this->soloSuperAdmin($request);
        $dati = $request->validate(['motivo_rifiuto' => ['nullable', 'string', 'max:1000']]);

        if (! $richiesta->isInAttesa()) {
            return back()->with('warning', 'La richiesta è già stata gestita.');
        }

        $registrazioni->rifiuta($richiesta, $superAdmin, $dati['motivo_rifiuto'] ?? null);

        return redirect()->route('richieste-registrazione.index')->with('success', 'Richiesta di '.$richiesta->nome.' rifiutata.');
    }

    private function soloSuperAdmin(Request $request): Utente
    {
        $utente = $request->user();
        abort_unless($utente instanceof Utente && $utente->isSuperAdmin(), 403);

        return $utente;
    }
}
