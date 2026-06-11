<?php

namespace App\Http\Requests\Soci;

/**
 * Creazione socio: numero_socio facoltativo (se vuoto viene assegnato
 * il progressivo per associazione — spec soci §1.5).
 */
class StoreSocioRequest extends SocioRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'numero_socio' => ['nullable', 'string', 'max:50'],
        ];
    }
}
