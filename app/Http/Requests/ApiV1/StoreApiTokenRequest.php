<?php

namespace App\Http\Requests\ApiV1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creazione personal access token Sanctum (miglioramento 23) dalla pagina
 * amministrativa. Le abilities usano la stessa sintassi dei permessi legacy.
 */
class StoreApiTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(StoreApiKeyRequest::PERMESSI)],
            'scadenza' => ['nullable', 'date', 'after:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nome.required' => 'Il nome del token è obbligatorio.',
            'abilities.required' => 'Seleziona almeno un permesso.',
            'scadenza.after' => 'La scadenza deve essere futura.',
        ];
    }
}
