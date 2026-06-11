<?php

namespace App\Http\Requests\Struttura;

class UpdatePrivacyPolicyRequest extends ConfigurazioniRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'privacy_policy_html' => ['nullable', 'string'],
        ];
    }
}
