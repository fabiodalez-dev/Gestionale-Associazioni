<?php

namespace App\Models\Concerns;

use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Allegati su medialibrary (collezione "allegati", disco privato
 * media_private): file serviti solo da AllegatiController dopo i controlli
 * della policy del modello. Usato da verbali, riunioni e progetti.
 */
trait HaAllegati
{
    use InteractsWithMedia;

    public const COLLEZIONE_ALLEGATI = 'allegati';

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::COLLEZIONE_ALLEGATI)->useDisk('media_private');
    }
}
