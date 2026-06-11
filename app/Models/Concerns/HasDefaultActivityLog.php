<?php

namespace App\Models\Concerns;

use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Configurazione di default per spatie/laravel-activitylog (v5):
 * logga gli attributi fillable (solo quelli modificati), escludendo
 * gli attributi sensibili dichiarati in $activityLogExcept.
 */
trait HasDefaultActivityLog
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logExcept(property_exists($this, 'activityLogExcept') ? $this->activityLogExcept : [])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
