<?php

namespace App\Http\Controllers\Struttura;

use App\Http\Controllers\Controller;
use App\Http\Requests\Struttura\StoreAmministratoreRequest;
use App\Http\Requests\Struttura\UpdateAmministratoreRequest;
use App\Mail\Struttura\InvitoAmministratoreMail;
use App\Models\Associazione;
use App\Models\Utente;
use App\Support\RuoliAssociazione;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

/**
 * Utenti amministratori — SOLO super_admin
 * (corregge la falla legacy: la pagina era raggiungibile da chiunque loggato).
 */
class AmministratoreController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Utente::class);

        $utenti = Utente::query()
            ->with('associazione')
            ->orderByDesc('ruolo') // super_admin prima (come nel legacy)
            ->orderBy('nome')
            ->get();

        $editUtente = $request->filled('edit')
            ? Utente::find($request->query('edit'))
            : null;

        // Solo associazioni attive nel select (spec §3.3)
        $associazioni = Associazione::query()
            ->where('attiva', true)
            ->orderBy('nome')
            ->get(['id', 'nome']);

        return view('struttura.amministratori.index', [
            'utenti' => $utenti,
            'editUtente' => $editUtente,
            'associazioni' => $associazioni,
            'ruoli' => Utente::RUOLI,
        ]);
    }

    public function store(StoreAmministratoreRequest $request): RedirectResponse
    {
        $dati = $request->validated();

        // Super admin non sono mai legati a un'associazione (spec §3.3)
        $associazioneId = $dati['ruolo'] === Utente::RUOLO_SUPER_ADMIN
            ? null
            : $dati['associazione_id'];

        $inviaInvito = $request->boolean('invia_invito');

        $utente = Utente::create([
            'associazione_id' => $associazioneId,
            'nome' => $dati['nome'],
            'cognome' => $dati['cognome'] ?? '',
            'email' => $dati['email'],
            // Con invito: password casuale temporanea, verrà impostata dall'invitato
            'password_hash' => $dati['password'] ?? Str::password(24),
            'ruolo' => $dati['ruolo'],
            'attivo' => true,
        ]);

        $utente->assignRole(Role::findOrCreate($dati['ruolo'], 'web'));

        $messaggio = 'Amministratore creato con successo.';

        if ($inviaInvito) {
            $messaggio .= $this->inviaInvito($utente)
                ? ' Invito inviato via email.'
                : ' ATTENZIONE: invio dell\'invito non riuscito.';
        }

        return redirect()->route('amministratori.index')->with('success', $messaggio);
    }

    public function update(UpdateAmministratoreRequest $request, Utente $utente): RedirectResponse
    {
        $dati = $request->validated();

        // Un operatore che resta operatore nella stessa associazione conserva le sue cariche
        $cariche = $utente->ruolo === Utente::RUOLO_OPERATORE
            && $dati['ruolo'] === Utente::RUOLO_OPERATORE
            && $utente->associazione_id === ($dati['associazione_id'] ?? null)
                ? $utente->getRoleNames()->intersect(array_keys(RuoliAssociazione::RUOLI_STAFF))->values()->all()
                : [];

        $utente->fill([
            'nome' => $dati['nome'],
            'cognome' => $dati['cognome'] ?? '',
            'email' => $dati['email'],
            'ruolo' => $dati['ruolo'],
            'associazione_id' => $dati['ruolo'] === Utente::RUOLO_SUPER_ADMIN
                ? null
                : $dati['associazione_id'],
        ]);

        if ($request->has('attivo')) {
            $utente->attivo = $request->boolean('attivo');
        }

        // Password opzionale: vuota = invariata (spec §3.4)
        if (! empty($dati['password'])) {
            $utente->password_hash = $dati['password']; // hashed dal cast
        }

        $utente->save();
        $utente->syncRoles($cariche !== [] ? $cariche : [Role::findOrCreate($dati['ruolo'], 'web')]);

        return redirect()->route('amministratori.index')->with('success', 'Amministratore aggiornato con successo.');
    }

    public function destroy(Request $request, Utente $utente): RedirectResponse
    {
        if ($request->user()->cannot('delete', $utente)) {
            $motivo = $utente->id === $request->user()->id
                ? 'Non puoi eliminare il tuo stesso account.'
                : ($utente->isSuperAdmin()
                    ? 'Impossibile eliminare l\'ultimo super admin.'
                    : 'Operazione non consentita.');

            return redirect()->route('amministratori.index')->with('error', $motivo);
        }

        $utente->delete();

        return redirect()->route('amministratori.index')->with('success', 'Amministratore eliminato.');
    }

    /**
     * Reinvio dell'invito a impostare la password.
     */
    public function reinviaInvito(Request $request, Utente $utente): RedirectResponse
    {
        Gate::authorize('update', $utente);

        $inviato = $this->inviaInvito($utente);

        return redirect()
            ->route('amministratori.index')
            ->with(
                $inviato ? 'success' : 'error',
                $inviato ? 'Invito reinviato.' : 'Invio dell\'invito non riuscito.'
            );
    }

    /**
     * Invio invito best-effort: link firmato valido 7 giorni.
     */
    private function inviaInvito(Utente $utente): bool
    {
        try {
            $urlInvito = URL::temporarySignedRoute(
                'invito.show',
                now()->addDays(7),
                ['utente' => $utente->id]
            );

            Mail::to($utente->email)->queue(new InvitoAmministratoreMail($utente, $urlInvito));

            return true;
        } catch (\Throwable $e) {
            Log::error('Invio invito amministratore fallito: '.$e->getMessage());

            return false;
        }
    }
}
