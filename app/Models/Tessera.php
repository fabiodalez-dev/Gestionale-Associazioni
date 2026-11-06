<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use App\Models\Concerns\NelPerimetroSedeTramite;
use App\Services\Tessere\VincoloTessereAnnoService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Tessera extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;
    use NelPerimetroSedeTramite;

    public const STATO_ATTIVA = 'Attiva';

    public const STATO_SCADUTA = 'Scaduta';

    public const STATO_SOSPESA = 'Sospesa';

    public const STATO_ANNULLATA = 'Annullata';

    public const STATI = [
        self::STATO_ATTIVA,
        self::STATO_SCADUTA,
        self::STATO_SOSPESA,
        self::STATO_ANNULLATA,
    ];

    public const TIPO_SCADENZA_SOLARE = 'solare';

    public const TIPO_SCADENZA_ANNUALE = 'annuale';

    protected $table = 'tessere';

    protected $fillable = [
        'associazione_id',
        'socio_id',
        'numero_tessera',
        'anno_validita',
        'data_emissione',
        'data_scadenza',
        'tipo_scadenza',
        'stato',
        'qr_code_url',
        'template_tessera',
        'evento_creazione_id',
    ];

    /**
     * Colonna tecnica del vincolo unique (socio_id, anno_non_annullata):
     * non si assegna a mano e non si mostra.
     */
    protected $hidden = [
        'anno_non_annullata',
    ];

    /**
     * Una sola tessera NON annullata per socio e anno (issue #21). MariaDB
     * non ha indici parziali, quindi il vincolo unique è su una colonna
     * derivata: anno_validita per le tessere non annullate, NULL per le
     * annullate (i NULL non collidono nell'indice unique, su SQLite come su
     * MariaDB). Così una tessera persa si annulla e se ne emette un'altra
     * per lo stesso anno. Solo 'Annullata' esce dal vincolo: una tessera con
     * stato 'Scaduta' memorizzato (valore legacy, non più selezionabile dal
     * backoffice) resta una tessera reale di quell'anno e blocca una nuova
     * emissione per lo stesso anno finché non viene annullata (scelta
     * esplicita, come nella migration e nelle regole delle FormRequest).
     * Tenuta allineata qui a ogni salvataggio Eloquent:
     * nessun flusso dell'applicazione scrive le tessere fuori dal modello.
     * Si ricalcola solo alla creazione o quando cambiano socio, anno o
     * stato: i doppioni legacy lasciati fuori dal vincolo dall'import
     * (anno_non_annullata NULL) non ci rientrano per un salvataggio che non
     * li riguarda (es. il QR URL persistito alla stampa), che altrimenti
     * violerebbe il vincolo al salvataggio del secondo doppione.
     */
    protected static function booted(): void
    {
        static::saving(function (Tessera $tessera): void {
            if ($tessera->exists && ! $tessera->isDirty(['socio_id', 'anno_validita', 'stato'])) {
                return;
            }

            $tessera->anno_non_annullata = $tessera->stato === self::STATO_ANNULLATA
                ? null
                : (int) $tessera->anno_validita;
        });

        // Doppioni rimasti fuori dal vincolo socio/anno (migration con
        // doppioni, import legacy): annullare, spostare o eliminare l'ultimo
        // doppione dal backoffice aggiunge l'indice o riporta dentro la tessera
        // rimasta, dopo il commit (DDL fuori transazione)
        // Solo le tessere del socio coinvolto (e del precedente, se la tessera
        // è stata spostata): niente scansione dell'intera tabella a ogni modifica
        $sistemaDopoIlCommit = function (Tessera $tessera): void {
            $soci = array_values(array_unique(array_filter([
                (string) $tessera->socio_id,
                (string) $tessera->getOriginal('socio_id'),
            ])));
            DB::afterCommit(fn () => app(VincoloTessereAnnoService::class)->sistema($soci));
        };
        static::saved(function (Tessera $tessera) use ($sistemaDopoIlCommit): void {
            if ($tessera->wasChanged(['socio_id', 'anno_validita', 'stato'])) {
                $sistemaDopoIlCommit($tessera);
            }
        });
        static::deleted($sistemaDopoIlCommit);
    }

    /**
     * Cast degli anni (anche della colonna del vincolo) e delle date.
     */
    protected function casts(): array
    {
        return [
            'anno_validita' => 'integer',
            'anno_non_annullata' => 'integer',
            'data_emissione' => 'date',
            'data_scadenza' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }

    /**
     * @return BelongsTo<Evento, $this>
     */
    public function eventoCreazione(): BelongsTo
    {
        return $this->belongsTo(Evento::class, 'evento_creazione_id');
    }

    /**
     * Validità effettiva: stato 'Attiva' e non scaduta (lo stato 'Scaduta'
     * sul DB è denormalizzato, la verità è data_scadenza vs oggi).
     */
    public function isValida(): bool
    {
        return $this->stato === self::STATO_ATTIVA
            && $this->data_scadenza->gte(now()->startOfDay());
    }

    /**
     * Scaduta di fatto (a prescindere dallo stato sul DB).
     */
    public function isScadutaEffettiva(): bool
    {
        return $this->data_scadenza->lt(now()->startOfDay());
    }

    /**
     * Stato mostrato in UI: una tessera 'Attiva' ma con data_scadenza
     * superata viene visualizzata come 'Scaduta' (stato derivato, §4.7 spec).
     */
    public function statoVisualizzato(): string
    {
        if ($this->stato === self::STATO_ATTIVA && $this->isScadutaEffettiva()) {
            return self::STATO_SCADUTA;
        }

        return $this->stato;
    }

    /**
     * Tessere effettivamente valide (stato Attiva e non scadute).
     * Definizione unica per lista, filtri e verifica (fix incoerenza legacy §9.2).
     */
    public function scopeValide(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('stato'), self::STATO_ATTIVA)
            ->whereDate($query->qualifyColumn('data_scadenza'), '>=', now()->toDateString());
    }

    /**
     * Tessere scadute di fatto: stato 'Scaduta' oppure data_scadenza superata.
     */
    public function scopeScaduteEffettive(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where($q->qualifyColumn('stato'), self::STATO_SCADUTA)
                ->orWhereDate($q->qualifyColumn('data_scadenza'), '<', now()->toDateString());
        });
    }

    /**
     * Tessere in scadenza entro $days giorni (default da associazione, §9.11).
     */
    public function scopeInScadenza(Builder $query, int $days = 30): Builder
    {
        $table = $this->getTable();

        return $query->whereDate($table.'.data_scadenza', '>=', now()->toDateString())
            ->whereDate($table.'.data_scadenza', '<=', now()->addDays($days)->toDateString());
    }
}
