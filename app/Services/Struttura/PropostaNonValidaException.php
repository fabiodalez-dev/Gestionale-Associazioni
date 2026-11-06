<?php

namespace App\Services\Struttura;

use RuntimeException;

/**
 * Operazione su una proposta dei soci non ammessa (issue #10): il messaggio
 * è pensato per essere mostrato direttamente al socio o alla segreteria.
 */
class PropostaNonValidaException extends RuntimeException {}
