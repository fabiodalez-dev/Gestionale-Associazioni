<?php

namespace App\Http\Requests\Struttura;

class UpdateTemplateEmailRequest extends ConfigurazioniRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'template_email_scadenza' => ['nullable', 'string', 'max:65535'],
        ];
    }
}
