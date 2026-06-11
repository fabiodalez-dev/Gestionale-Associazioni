<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasUuids;

    protected $table = 'tags';

    protected $fillable = [
        'associazione_id',
        'nome_tag',
        'colore',
    ];

    /**
     * @return BelongsToMany<Socio, $this, SocioTag>
     */
    public function soci(): BelongsToMany
    {
        // Niente withPivot('created_at'): la pivot non ha updated_at (vedi Socio::tags())
        return $this->belongsToMany(Socio::class, 'socio_tags', 'tag_id', 'socio_id')
            ->using(SocioTag::class);
    }
}
