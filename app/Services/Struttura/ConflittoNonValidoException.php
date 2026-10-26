<?php

namespace App\Services\Struttura;

use RuntimeException;

/**
 * Dichiarazione di interessi o conflitto non registrabile (issue #11): il
 * messaggio è pensato per essere mostrato direttamente al socio o alla
 * segreteria.
 */
class ConflittoNonValidoException extends RuntimeException {}
