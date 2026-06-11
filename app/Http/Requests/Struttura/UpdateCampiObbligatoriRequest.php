<?php

namespace App\Http\Requests\Struttura;

class UpdateCampiObbligatoriRequest extends ConfigurazioniRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'required_backend' => ['nullable', 'array'],
            'required_preiscrizione' => ['nullable', 'array'],
            'required_custom_backend' => ['nullable', 'array'],
            'required_custom_backend.*' => ['uuid'],
            'required_custom_preiscrizione' => ['nullable', 'array'],
            'required_custom_preiscrizione.*' => ['uuid'],
        ];
    }
}
