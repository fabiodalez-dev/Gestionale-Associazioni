<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot socio <-> tag (PK composita socio_id + tag_id, solo created_at).
 */
class SocioTag extends Pivot
{
    protected $table = 'socio_tags';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'socio_id',
        'tag_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
