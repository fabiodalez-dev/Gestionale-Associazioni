<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
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
    use SoftDeletes;

    public const COLLEZIONE_FILE = 'file';

    protected $table = 'documenti_socio';

    protected $fillable = [
        'associazione_id',
        'socio_id',
        'nome_file',
        'percorso_file',
        'descrizione',
        'data_scadenza',
        'caricato_da',
    ];

    protected function casts(): array
    {
        return [
            'data_scadenza' => 'date',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::COLLEZIONE_FILE)
            ->useDisk('local')
            ->singleFile();
    }

    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }

    public function caricatoDa(): BelongsTo
    {
        return $this->belongsTo(Utente::class, 'caricato_da');
    }
}
