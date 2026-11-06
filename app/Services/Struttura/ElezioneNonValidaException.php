<?php

namespace App\Services\Struttura;

use RuntimeException;

/**
 * Operazione su un'elezione non ammessa (issue #9): il messaggio è pensato
 * per essere mostrato direttamente al socio o alla segreteria.
 */
class ElezioneNonValidaException extends RuntimeException {}
