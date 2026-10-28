<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Mews\Purifier\Facades\Purifier;

/**
 * Pagina del manuale operativo interno (issue #12): procedura in Markdown
 * semplice con passi facoltativi da usare come checklist. Le modifiche sono
 * nel log attività (storico revisioni con il testo precedente).
 */
class ManualePagina extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasUuids;

    public const VISIBILITA = [
        'staff' => 'Solo staff del gestionale',
        'soci' => 'Anche i soci, nell\'area riservata',
    ];

    /** Tag ammessi nel contenuto reso dal Markdown. */
    private const HTML_AMMESSO = 'h2,h3,h4,p,b,strong,i,em,u,ul,ol,li,a[href|title],br,blockquote,code,pre,hr';

    protected $table = 'manuale_pagine';

    protected $attributes = [
        'visibilita' => 'staff',
        'ordine' => 0,
    ];

    protected $fillable = [
        'associazione_id',
        'sezione',
        'titolo',
        'contenuto',
        'passi',
        'visibilita',
        'responsabile_utente_id',
        'revisionata_at',
        'ordine',
    ];

    protected function casts(): array
    {
        return [
            'passi' => 'array',
            'revisionata_at' => 'datetime',
            'ordine' => 'integer',
        ];
    }

    /**
     * Contenuto reso in HTML sicuro: Markdown senza HTML grezzo, poi ripulito.
     */
    public function contenutoHtml(): string
    {
        if (blank($this->contenuto)) {
            return '';
        }

        $html = Str::markdown((string) $this->contenuto, ['html_input' => 'escape', 'allow_unsafe_links' => false]);

        return (string) Purifier::clean($html, ['HTML.Allowed' => self::HTML_AMMESSO]);
    }

    /**
     * @return list<string>
     */
    public function elencoPassi(): array
    {
        return array_values(array_filter(array_map('trim', (array) ($this->passi ?? [])), fn ($p) => $p !== ''));
    }

    /**
     * @return BelongsTo<Utente, $this>
     */
    public function responsabile(): BelongsTo
    {
        return $this->belongsTo(Utente::class, 'responsabile_utente_id');
    }

    /**
     * @return HasMany<ManualeEsecuzione, $this>
     */
    public function esecuzioni(): HasMany
    {
        return $this->hasMany(ManualeEsecuzione::class, 'pagina_id');
    }
}
