<?php

namespace App\Services\Comunicazione;

use RuntimeException;

/**
 * Operazione non ammessa nella rubrica interna (issue #14): il messaggio è
 * pensato per essere mostrato direttamente al socio.
 */
class RubricaNonValidaException extends RuntimeException {}
