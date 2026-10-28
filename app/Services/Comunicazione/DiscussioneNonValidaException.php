<?php

namespace App\Services\Comunicazione;

use RuntimeException;

/**
 * Operazione non ammessa nelle discussioni tra soci (issue #13): il
 * messaggio è pensato per essere mostrato direttamente al socio.
 */
class DiscussioneNonValidaException extends RuntimeException {}
