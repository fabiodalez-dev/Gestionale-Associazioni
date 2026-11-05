<?php

namespace App\Services\Tessere;

use RuntimeException;

/**
 * Il socio ha già una tessera non annullata per l'anno richiesto (vincolo
 * unique socio + anno_non_annullata, issue #21). Il messaggio è in italiano e pensato
 * per essere mostrato all'utente come errore di validazione.
 */
class TesseraGiaPresenteException extends RuntimeException
{
    /**
     * L'anno richiesto serve solo a comporre il messaggio.
     */
    public function __construct(int $anno)
    {
        parent::__construct("Il socio ha già una tessera non annullata per l'anno {$anno}.");
    }
}
