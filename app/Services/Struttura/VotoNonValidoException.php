<?php

namespace App\Services\Struttura;

use RuntimeException;

/**
 * Operazione di voto in assemblea non ammessa (issue #8): il messaggio è
 * pensato per essere mostrato direttamente al socio o alla segreteria.
 */
class VotoNonValidoException extends RuntimeException {}
