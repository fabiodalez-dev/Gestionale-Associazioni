<?php

namespace App\Services\Struttura;

use RuntimeException;

/**
 * Delega per l'assemblea non registrabile o non revocabile (issue #7):
 * il messaggio è pensato per essere mostrato direttamente al socio o
 * alla segreteria.
 */
class DelegaNonValidaException extends RuntimeException {}
