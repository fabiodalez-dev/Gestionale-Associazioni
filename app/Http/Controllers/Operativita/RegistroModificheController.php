<?php

namespace App\Http\Controllers\Operativita;

use App\Http\Controllers\Controller;
use App\Models\AttivitaAdmin;
use App\Models\Documento;
use App\Models\Evento;
use App\Models\NotaSocio;
use App\Models\Prenotazione;
use App\Models\Quota;
use App\Models\Scadenza;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\Utente;
use App\Models\Verbale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Spatie\Activitylog\Models\Activity;

/**
 * Registro modifiche (f.6 del piano "Gestione 2"): UI di consultazione del
 * log spatie/activitylog già attivo su tutti i modelli (HasDefaultActivityLog).
 * Filtri per modello/utente/periodo/azione, diff leggibile dei campi
 * modificati, paginazione. Permesso struttura.view.
 *
 * Isolamento tenant: la tabella activity_log non ha associazione_id, quindi
 * il perimetro è "subject appartenente al tenant" per ciascun modello
 * whitelisted (riga ancora presente nella tabella del modello, oppure
 * associazione_id loggato in properties per le righe eliminate). Copre sia
 * le azioni degli admin del tenant sia quelle del super_admin sui dati del
 * tenant selezionato; il solo causer non basta mai (un utente spostato di
 * tenant porterebbe con sé il log di un'altra associazione).
 */
class RegistroModificheController extends Controller
{
    /**
     * Modelli consultabili (etichetta → classe). Tutti hanno associazione_id.
     *
     * @var array<string, class-string>
     */
    public const MODELLI = [
        'Socio' => Socio::class,
        'Tessera' => Tessera::class,
        'Quota' => Quota::class,
        'Evento' => Evento::class,
        'Prenotazione' => Prenotazione::class,
        'Documento' => Documento::class,
        'Verbale' => Verbale::class,
        'Scadenza' => Scadenza::class,
        'Nota socio' => NotaSocio::class,
        'Attività admin' => AttivitaAdmin::class,
    ];

    public const AZIONI = ['created', 'updated', 'deleted', 'restored'];

    public function __invoke(Request $request): View
    {
        /** @var Utente $utente */
        $utente = $request->user();

        abort_unless($this->autorizzato($utente), 403);

        $modello = (string) $request->query('modello', '');
        if (! array_key_exists($modello, self::MODELLI)) {
            $modello = '';
        }

        $azione = (string) $request->query('azione', '');
        if (! in_array($azione, self::AZIONI, true)) {
            $azione = '';
        }

        $utenteId = (string) $request->query('utente_id', '');
        $dal = (string) $request->query('dal', '');
        $al = (string) $request->query('al', '');

        $tenantId = Socio::currentAssociazioneId();

        $utentiTenant = Utente::query()
            ->when($tenantId !== null, fn ($q) => $q->where('associazione_id', $tenantId))
            ->orderBy('nome')
            ->orderBy('cognome')
            ->get(['id', 'nome', 'cognome', 'email']);

        $attivita = Activity::query()
            ->with('causer')
            ->when($tenantId !== null, fn ($q) => $this->scopeTenant($q, $tenantId))
            ->when($modello !== '', fn ($q) => $q->where('subject_type', self::MODELLI[$modello]))
            ->when($azione !== '', fn ($q) => $q->where('event', $azione))
            ->when($utenteId !== '', fn ($q) => $q->where('causer_type', Utente::class)->where('causer_id', $utenteId))
            ->when($dal !== '', fn ($q) => $q->whereDate('created_at', '>=', $dal))
            ->when($al !== '', fn ($q) => $q->whereDate('created_at', '<=', $al))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return view('operativita.registro-modifiche.index', [
            'attivita' => $attivita,
            'modelli' => self::MODELLI,
            'azioni' => self::AZIONI,
            'utentiTenant' => $utentiTenant,
            'filtri' => [
                'modello' => $modello,
                'azione' => $azione,
                'utente_id' => $utenteId,
                'dal' => $dal,
                'al' => $al,
            ],
            'etichetteModelli' => array_flip(self::MODELLI),
        ]);
    }

    /**
     * Perimetro tenant del log: subject del tenant per ogni modello
     * whitelisted. La riga è del tenant se esiste (anche soft-deleted) nella
     * tabella del modello con associazione_id = tenant, oppure — per le righe
     * eliminate fisicamente — se l'associazione_id loggato nei cambiamenti
     * dell'attività (attribute_changes.attributes su created,
     * attribute_changes.old su deleted) è quello del tenant. Nessun ramo
     * "solo causer": chi ha fatto la modifica non dice a quale tenant
     * appartiene il dato.
     *
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    private function scopeTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where(function (Builder $q) use ($tenantId) {
            foreach (self::MODELLI as $classe) {
                $tabella = (new $classe)->getTable();

                $q->orWhere(fn (Builder $w) => $w
                    ->where('subject_type', $classe)
                    ->where(fn (Builder $s) => $s
                        // Query base (DB::table) per includere anche i soft-deleted
                        ->whereIn('subject_id', DB::table($tabella)
                            ->where('associazione_id', $tenantId)
                            ->select('id'))
                        ->orWhere('attribute_changes->attributes->associazione_id', $tenantId)
                        ->orWhere('attribute_changes->old->associazione_id', $tenantId)));
            }
        });
    }

    /**
     * Permesso struttura.view con fallback su ruolo (stessa logica delle
     * Policy quando i permessi spatie non sono seedati).
     */
    private function autorizzato(Utente $utente): bool
    {
        if ($utente->isSuperAdmin()) {
            return true;
        }

        try {
            return $utente->hasPermissionTo('struttura.view');
        } catch (\Throwable) {
            return $utente->isAdminAssociazione();
        }
    }
}
