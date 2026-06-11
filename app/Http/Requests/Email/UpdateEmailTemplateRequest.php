<?php

namespace App\Http\Requests\Email;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Aggiornamento template email: codice e nome non sono modificabili
 * dall'utente; non è prevista creazione/eliminazione (solo gli 8 predefiniti).
 * L'autorizzazione puntuale (policy update sul template) è nel controller.
 */
class UpdateEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'oggetto' => ['required', 'string', 'max:500'],
            'corpo_html' => ['required', 'string'],
            'corpo_json' => ['nullable', 'string', 'json'],
            'attivo' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Pulizia caratteri di controllo nell'oggetto (come il legacy)
        if (is_string($this->input('oggetto'))) {
            $this->merge([
                'oggetto' => preg_replace('/[\x00-\x1F\x7F]/u', '', $this->input('oggetto')),
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'oggetto' => 'oggetto',
            'corpo_html' => 'corpo email',
        ];
    }
}
