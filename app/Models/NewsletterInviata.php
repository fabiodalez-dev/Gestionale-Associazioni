<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Storico delle newsletter inviate (f.8 del piano "Gestione 2"):
 * titolo, annunci inclusi (uuid in json), numero di destinatari e data invio.
 */
class NewsletterInviata extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;

    protected $table = 'newsletter_inviate';

    protected $fillable = [
        'associazione_id',
        'titolo',
        'annunci_ids',
        'inviata_a',
        'inviata_at',
        'inviata_da',
    ];

    protected function casts(): array
    {
        return [
            'annunci_ids' => 'array',
            'inviata_a' => 'integer',
            'inviata_at' => 'datetime',
        ];
    }

    /**
     * Gli annunci inclusi nella newsletter, nell'ordine salvato.
     *
     * @return Collection<int, Annuncio>
     */
    public function annunci(): Collection
    {
        $ids = array_values(array_filter((array) $this->annunci_ids));

        if ($ids === []) {
            /** @var Collection<int, Annuncio> */
            return new Collection;
        }

        return Annuncio::query()
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (Annuncio $annuncio) => array_search($annuncio->id, $ids, true))
            ->values();
    }

    /**
     * Admin che ha inviato la newsletter.
     *
     * @return BelongsTo<Utente, $this>
     */
    public function inviataDa(): BelongsTo
    {
        return $this->belongsTo(Utente::class, 'inviata_da');
    }
}
