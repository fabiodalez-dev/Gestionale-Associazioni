<?php

namespace App\Http\Requests\Comunicazione;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Toggle per tenant degli auguri di compleanno automatici (f.9).
 * L'autorizzazione (manageSettings, come le altre configurazioni)
 * è nel controller.
 */
class AuguriConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['auguri_automatici' => $this->boolean('auguri_automatici')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'auguri_automatici' => ['required', 'boolean'],
        ];
    }
}
