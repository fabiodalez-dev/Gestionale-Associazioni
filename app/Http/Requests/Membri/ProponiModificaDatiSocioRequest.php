<?php

namespace App\Http\Requests\Membri;

use App\Models\Socio;

/**
 * Proposta di modifica dati dall'AREA SOCI (guard 'socio'): il socio
 * autenticato propone modifiche per sé stesso.
 */
class ProponiModificaDatiSocioRequest extends ProponiModificaDatiRequest
{
    public function socioTarget(): ?Socio
    {
        $socio = $this->user('socio');

        return $socio instanceof Socio ? $socio : null;
    }
}
