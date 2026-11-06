<?php

namespace App\Services\Membri;

use RuntimeException;

/**
 * Errore di dominio delle richieste di modifica dati (f.19).
 *
 * Il messaggio è in italiano e pensato per essere mostrato all'admin
 * (il controller lo riporta come flash 'error', niente stack trace).
 */
class ModificaDatiException extends RuntimeException
{
    /**
     * Il valore proposto è stato preso da un altro socio dopo la richiesta.
     */
    public static function valoreNonPiuUnico(string $etichetta): self
    {
        return new self("Il valore proposto per {$etichetta} appartiene ormai a un altro socio: rifiuta la richiesta o correggi i dati.");
    }
}
