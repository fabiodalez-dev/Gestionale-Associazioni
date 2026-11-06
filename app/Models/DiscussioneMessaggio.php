<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Messaggio di una discussione (issue #13): testo semplice, mostrato con
 * escape e link resi cliccabili. Nascosto dalla moderazione, non cancellato.
 */
class DiscussioneMessaggio extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasUuids;

    protected $table = 'discussioni_messaggi';

    protected $fillable = [
        'associazione_id',
        'discussione_id',
        'socio_id',
        'testo',
        'nascosto_at',
        'nascosto_da',
    ];

    protected function casts(): array
    {
        return [
            'nascosto_at' => 'datetime',
        ];
    }

    /**
     * Testo con escape, a capo conservati e link http(s) cliccabili.
     */
    public function testoHtml(): string
    {
        $html = nl2br(e($this->testo));

        return (string) preg_replace(
            '~(https?://[^\s<]+)~i',
            '<a href="$1" target="_blank" rel="noopener nofollow ugc">$1</a>',
            $html,
        );
    }

    /**
     * @param  Builder<DiscussioneMessaggio>  $query
     * @return Builder<DiscussioneMessaggio>
     */
    public function scopeVisibili(Builder $query): Builder
    {
        return $query->whereNull('nascosto_at');
    }

    /**
     * @return BelongsTo<Discussione, $this>
     */
    public function discussione(): BelongsTo
    {
        return $this->belongsTo(Discussione::class, 'discussione_id');
    }

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function autore(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }

    /**
     * @return HasMany<DiscussioneSegnalazione, $this>
     */
    public function segnalazioni(): HasMany
    {
        return $this->hasMany(DiscussioneSegnalazione::class, 'messaggio_id');
    }
}
