<?php

namespace App\Http\Controllers\Struttura;

use App\Http\Controllers\Controller;
use App\Http\Requests\Struttura\StoreAssociazioneRequest;
use App\Http\Requests\Struttura\UpdateAssociazioneRequest;
use App\Models\Associazione;
use App\Models\Sede;
use App\Models\Utente;
use App\Services\Struttura\CloneAssociazioneConfigService;
use App\Services\Struttura\LogoStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

/**
 * Gestione associazioni (tenant) — SOLO super_admin (spec struttura §1).
 */
class AssociazioneController extends Controller
{
    public function __construct(
        private readonly LogoStorageService $logoStorage,
        private readonly CloneAssociazioneConfigService $cloneService,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Associazione::class);

        // withCount senza scope tenant: i conteggi sono cross-tenant per definizione
        $associazioni = Associazione::query()
            ->withCount([
                'soci' => fn ($q) => $q->withoutGlobalScope('associazione'),
                'sedi' => fn ($q) => $q->withoutGlobalScope('associazione'),
            ])
            ->orderBy('nome')
            ->get();

        // Modalità edit via GET ?edit=<id>: la modale si apre precompilata (come nel legacy)
        $editAssociazione = null;
        if ($request->filled('edit')) {
            $editAssociazione = Associazione::find($request->query('edit'));
        }

        $adminCorrenti = $editAssociazione
            ? Utente::query()
                ->where('associazione_id', $editAssociazione->id)
                ->where('ruolo', Utente::RUOLO_ADMIN_ASSOCIAZIONE)
                ->orderBy('nome')
                ->get()
            : collect();

        return view('struttura.associazioni.index', [
            'associazioni' => $associazioni,
            'editAssociazione' => $editAssociazione,
            'adminCorrenti' => $adminCorrenti,
        ]);
    }

    public function store(StoreAssociazioneRequest $request): RedirectResponse
    {
        $dati = $request->validated();
        $warnings = [];

        try {
            $associazione = DB::transaction(function () use ($request, $dati, &$warnings): Associazione {
                $associazione = Associazione::create([
                    'nome' => $dati['nome'],
                    'email' => $dati['email'],
                    'partita_iva' => $dati['partita_iva'] ?? null,
                    'codice_fiscale' => $dati['codice_fiscale'] ?? null,
                    'telefono' => $dati['telefono'] ?? null,
                    'indirizzo' => $dati['indirizzo'] ?? null,
                    'citta' => $dati['citta'] ?? null,
                    'provincia' => $dati['provincia'] ?? null,
                    'cap' => $dati['cap'] ?? null,
                    'tipo_scadenza_default' => $dati['tipo_scadenza_default'] ?? 'solare',
                    'giorni_notifica_scadenza' => $dati['giorni_notifica_scadenza'] ?? 30,
                    'costo_tessera' => $dati['costo_tessera'] ?? null,
                    'attiva' => $request->boolean('attiva'),
                ]);

                // Logo (soft: un errore non annulla l'operazione principale)
                $this->salvaLogo($request, $associazione, $warnings);

                // Collegamento admin esistente (prioritario sulla creazione)
                $adminCollegato = $this->collegaAdminEsistente(
                    $request->input('link_admin_user_id'),
                    $associazione,
                    $warnings,
                    soloDisponibili: true
                );

                // Creazione nuovo admin contestuale (saltata se collegato un esistente)
                if (! $adminCollegato && $request->boolean('create_admin')) {
                    $this->creaAdmin($dati, $associazione);
                }

                // Sede iniziale
                if ($request->boolean('create_sede')) {
                    Sede::create([
                        'associazione_id' => $associazione->id,
                        'nome' => ($dati['sede_nome'] ?? null) ?: 'Sede Principale',
                        'indirizzo' => $dati['sede_indirizzo'] ?? null,
                        'citta' => $dati['sede_citta'] ?? null,
                        'responsabile' => $dati['sede_responsabile'] ?? null,
                    ]);
                }

                // Clonazione configurazioni da un'altra associazione
                if ($request->boolean('clone_enable') && ! empty($dati['clone_from_assoc_id'])) {
                    $sorgente = Associazione::findOrFail($dati['clone_from_assoc_id']);
                    $this->cloneService->clone($sorgente, $associazione, $this->cloneFlags($request));
                }

                return $associazione;
            });
        } catch (\Throwable $e) {
            Log::error('Creazione associazione fallita: '.$e->getMessage());

            return back()
                ->withInput()
                ->with('error', 'Errore durante la creazione dell\'associazione. Riprova.');
        }

        return redirect()
            ->route('associazioni.index')
            ->with('success', 'Associazione "'.$associazione->nome.'" creata con successo.')
            ->with('warning', $warnings ? implode(' ', $warnings) : null);
    }

    public function update(UpdateAssociazioneRequest $request, Associazione $associazione): RedirectResponse
    {
        $dati = $request->validated();
        $warnings = [];

        try {
            DB::transaction(function () use ($request, $dati, $associazione, &$warnings): void {
                $associazione->update([
                    'nome' => $dati['nome'],
                    'email' => $dati['email'],
                    'partita_iva' => $dati['partita_iva'] ?? null,
                    'codice_fiscale' => $dati['codice_fiscale'] ?? null,
                    'telefono' => $dati['telefono'] ?? null,
                    'indirizzo' => $dati['indirizzo'] ?? null,
                    'citta' => $dati['citta'] ?? null,
                    'provincia' => $dati['provincia'] ?? null,
                    'cap' => $dati['cap'] ?? null,
                    'tipo_scadenza_default' => $dati['tipo_scadenza_default'] ?? 'solare',
                    'giorni_notifica_scadenza' => $dati['giorni_notifica_scadenza'] ?? 30,
                    'costo_tessera' => $dati['costo_tessera'] ?? null,
                    'attiva' => $request->boolean('attiva'),
                ]);

                $this->salvaLogo($request, $associazione, $warnings);

                $adminCollegato = $this->collegaAdminEsistente(
                    $request->input('link_admin_user_id'),
                    $associazione,
                    $warnings,
                    soloDisponibili: true
                );

                if (! $adminCollegato && $request->boolean('create_admin')) {
                    $this->creaAdmin($dati, $associazione);
                }

                if ($request->boolean('clone_enable') && ! empty($dati['clone_from_assoc_id'])) {
                    $sorgente = Associazione::findOrFail($dati['clone_from_assoc_id']);
                    $this->cloneService->clone($sorgente, $associazione, $this->cloneFlags($request));
                }
            });
        } catch (\Throwable $e) {
            Log::error('Aggiornamento associazione fallito: '.$e->getMessage());

            return back()
                ->withInput()
                ->with('error', 'Errore durante l\'aggiornamento dell\'associazione. Riprova.');
        }

        return redirect()
            ->route('associazioni.index')
            ->with('success', 'Associazione aggiornata con successo.')
            ->with('warning', $warnings ? implode(' ', $warnings) : null);
    }

    /**
     * Rimuove un admin dall'associazione: scollega soltanto
     * (associazione_id → NULL), il ruolo NON viene cambiato (spec §1.5).
     */
    public function removeAdmin(Request $request, Associazione $associazione): RedirectResponse
    {
        Gate::authorize('update', $associazione);

        $request->validate(['utente_id' => ['required', 'uuid']]);

        $aggiornati = Utente::query()
            ->where('id', $request->input('utente_id'))
            ->where('associazione_id', $associazione->id) // scoping difensivo
            ->update(['associazione_id' => null]);

        return redirect()
            ->route('associazioni.index', ['edit' => $associazione->id])
            ->with(
                $aggiornati ? 'success' : 'warning',
                $aggiornati ? 'Amministratore rimosso dall\'associazione.' : 'Amministratore non trovato per questa associazione.'
            );
    }

    /**
     * Eliminazione con conferma forte (digitare il nome — spec §9.16):
     * i dati figli vengono rimossi per cascade FK, gli utenti restano scollegati.
     */
    public function destroy(Request $request, Associazione $associazione): RedirectResponse
    {
        Gate::authorize('delete', $associazione);

        $request->validate(
            ['conferma_nome' => ['required', 'string', \Illuminate\Validation\Rule::in([$associazione->nome])]],
            ['conferma_nome.in' => 'Il nome digitato non corrisponde: eliminazione annullata.']
        );

        // Gli utenti collegati vengono scollegati (il cascade DB li eliminerebbe)
        Utente::query()->where('associazione_id', $associazione->id)->update(['associazione_id' => null]);

        $nome = $associazione->nome;
        $associazione->delete();

        return redirect()
            ->route('associazioni.index')
            ->with('success', 'Associazione "'.$nome.'" eliminata con tutti i dati collegati.');
    }

    /**
     * Upload logo soft: fallimento → warning, l'operazione principale resta valida (spec §1.3.1).
     *
     * @param  list<string>  $warnings
     */
    private function salvaLogo(Request $request, Associazione $associazione, array &$warnings): void
    {
        if (! $request->hasFile('logo_file')) {
            return;
        }

        try {
            $vecchioLogo = $associazione->logo_url;
            $path = $this->logoStorage->store($request->file('logo_file'));
            $associazione->update(['logo_url' => $path]);
            $this->logoStorage->delete($vecchioLogo);
        } catch (\Throwable $e) {
            Log::warning('Upload logo fallito: '.$e->getMessage());
            $warnings[] = '(Logo non salvato)';
        }
    }

    /**
     * Collega un utente esistente all'associazione. Se non è super_admin il ruolo
     * viene forzato ad admin_associazione; il super_admin mantiene il proprio ruolo.
     *
     * @param  list<string>  $warnings
     */
    private function collegaAdminEsistente(?string $utenteId, Associazione $associazione, array &$warnings, bool $soloDisponibili): bool
    {
        if (blank($utenteId)) {
            return false;
        }

        $query = Utente::query()->where('id', $utenteId);
        if ($soloDisponibili) {
            $query->whereNull('associazione_id');
        }
        $utente = $query->first();

        if (! $utente) {
            $warnings[] = '(Admin esistente non collegato: non trovato o già collegato)';

            return false;
        }

        $utente->associazione_id = $associazione->id;
        if (! $utente->isSuperAdmin()) {
            $utente->ruolo = Utente::RUOLO_ADMIN_ASSOCIAZIONE;
            $utente->syncRoles([Role::findOrCreate(Utente::RUOLO_ADMIN_ASSOCIAZIONE, 'web')]);
        }
        $utente->save();

        return true;
    }

    /**
     * Crea un nuovo admin_associazione collegato (i dati sono già validati in modo
     * bloccante dal Form Request — semantica irrobustita rispetto al warning legacy).
     *
     * @param  array<string, mixed>  $dati
     */
    private function creaAdmin(array $dati, Associazione $associazione): void
    {
        $utente = Utente::create([
            'associazione_id' => $associazione->id,
            'nome' => $dati['admin_nome'],
            'cognome' => $dati['admin_cognome'] ?? '',
            'email' => $dati['admin_email'],
            'password_hash' => $dati['admin_password'], // hashed dal cast
            'ruolo' => Utente::RUOLO_ADMIN_ASSOCIAZIONE,
            'attivo' => true,
        ]);

        $utente->assignRole(Role::findOrCreate(Utente::RUOLO_ADMIN_ASSOCIAZIONE, 'web'));
    }

    /**
     * @return array{tipi: bool, categorie: bool, sedi: bool, campi: bool, tags: bool, gruppi: bool, templates: bool}
     */
    private function cloneFlags(Request $request): array
    {
        return [
            'tipi' => $request->boolean('clone_tipi'),
            'categorie' => $request->boolean('clone_categorie'),
            'sedi' => $request->boolean('clone_sedi'),
            'campi' => $request->boolean('clone_campi'),
            'tags' => $request->boolean('clone_tags'),
            'gruppi' => $request->boolean('clone_gruppi'),
            'templates' => $request->boolean('clone_templates'),
        ];
    }
}
