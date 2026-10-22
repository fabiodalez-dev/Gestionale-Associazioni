<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use App\Models\Concerns\NelPerimetroSedeTramite;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Documento del fascicolo del singolo socio (estensione §6.1 della spec):
 * stesso storage privato dei documenti associazione (medialibrary),
 * con eventuale data di scadenza del documento (es. certificato medico).
 */
class DocumentoSocio extends Model implements HasMedia
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasUuids;
    use InteractsWithMedia;
    use NelPerimetroSedeTramite;
    use SoftDeletes;

    public const COLLEZIONE_FILE = 'file';

    /** Tipi documento suggeriti (la colonna tipo_documento resta una string libera, f.23). */
    public const TIPI_DOCUMENTO = [
        'certificato_medico' => 'Certificato medico',
        'documento_identita' => 'Documento identità',
        'tessera_sanitaria' => 'Tessera sanitaria',
        'autocertificazione' => 'Autocertificazione',
        'altro' => 'Altro',
    ];

    /** Giorni di preavviso per il promemoria documento in scadenza (f.23). */
    public const GIORNI_PREAVVISO_SCADENZA = 30;

    protected $table = 'documenti_socio';

    protected $fillable = [
        'associazione_id',
        'socio_id',
        'nome_file',
        'percorso_file',
        'descrizione',
        'tipo_documento',
        'data_scadenza',
        'caricato_da',
    ];

    protected function casts(): array
    {
        return [
            'data_scadenza' => 'date',
        ];
    }

    public function isScaduto(): bool
    {
        return $this->data_scadenza !== null
            && $this->data_scadenza->lt(now()->startOfDay());
    }

    public function isInScadenza(int $giorni = self::GIORNI_PREAVVISO_SCADENZA): bool
    {
        return $this->data_scadenza !== null
            && ! $this->isScaduto()
            && $this->data_scadenza->lte(now()->startOfDay()->addDays($giorni));
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::COLLEZIONE_FILE)
            ->useDisk('local')
            ->singleFile();
    }

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }

    /**
     * @return BelongsTo<Utente, $this>
     */
    public function caricatoDa(): BelongsTo
    {
        return $this->belongsTo(Utente::class, 'caricato_da');
    }
}
