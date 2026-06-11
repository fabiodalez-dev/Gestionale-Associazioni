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
 * Documento a livello di associazione (statuti, regolamenti, verbali scansionati...).
 *
 * Il file fisico è gestito con spatie/medialibrary (collezione 'file', disk privato):
 * niente accesso anonimo per URL come nel legacy — il download passa da una rotta
 * autenticata con Policy oppure da un link firmato temporaneo (miglioramento 16).
 * `nome_file`/`percorso_file` restano valorizzati per compatibilità con i dati migrati.
 */
class Documento extends Model implements HasMedia
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasUuids;
    use InteractsWithMedia;
    use SoftDeletes;

    public const COLLEZIONE_FILE = 'file';

    public const ESTENSIONI_PERMESSE = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png'];

    protected $table = 'documenti';

    public const UPDATED_AT = null;

    protected $fillable = [
        'associazione_id',
        'nome_file',
        'percorso_file',
        'descrizione',
        'categoria',
        'dimensione_kb',
        'caricato_da',
    ];

    protected function casts(): array
    {
        return [
            'dimensione_kb' => 'integer',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::COLLEZIONE_FILE)
            ->useDisk('local')
            ->singleFile();
    }

    public function caricatoDa(): BelongsTo
    {
        return $this->belongsTo(Utente::class, 'caricato_da');
    }
}
