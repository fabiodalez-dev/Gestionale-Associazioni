<?php

namespace App\Services\Registro;

use App\Models\Socio;
use RuntimeException;

/**
 * Cessazione non registrabile per lo stato corrente del socio (f.15):
 * il messaggio è pensato per essere mostrato direttamente all'admin.
 */
class CessazioneNonAmmessaException extends RuntimeException
{
    public static function perStato(Socio $socio): self
    {
        return new self(
            "Impossibile registrare la cessazione: {$socio->nome_completo} è già in stato \"{$socio->stato}\"."
        );
    }
}
