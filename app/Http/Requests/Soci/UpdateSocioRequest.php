<?php

namespace App\Http\Requests\Soci;

use Illuminate\Validation\Rule;

/**
 * Modifica socio: numero_socio editabile e obbligatorio (spec soci §1.4),
 * unico per associazione.
 */
class UpdateSocioRequest extends SocioRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'numero_socio' => [
                'required', 'string', 'max:50',
                Rule::unique('soci', 'numero_socio')
                    ->where('associazione_id', $this->associazioneId())
                    ->ignore($this->socioInModifica()?->id),
            ],
        ];
    }
}
