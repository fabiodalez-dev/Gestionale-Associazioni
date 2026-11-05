<?php

namespace App\Http\Requests\Soci;

use Illuminate\Validation\Rule;

/**
 * Creazione socio: numero_socio facoltativo (se vuoto viene assegnato
 * il progressivo per associazione — spec soci §1.5), ma se indicato deve
 * essere unico per associazione come in modifica (il vincolo DB conta
 * anche i soci nel cestino: niente filtro su deleted_at).
 */
class StoreSocioRequest extends SocioRequest
{
    /**
     * Regole del socio più il numero socio unico nel tenant.
     */
    public function rules(): array
    {
        return parent::rules() + [
            'numero_socio' => [
                'nullable', 'string', 'max:50',
                Rule::unique('soci', 'numero_socio')->where('associazione_id', $this->associazioneId()),
            ],
        ];
    }
}
