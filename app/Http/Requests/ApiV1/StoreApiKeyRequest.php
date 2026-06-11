<?php

namespace App\Http\Requests\ApiV1;

use App\Models\ApiKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creazione chiave API dalla pagina amministrativa.
 */
class StoreApiKeyRequest extends FormRequest
{
    /** Permessi assegnabili (catalogo legacy §1.4). */
    public const PERMESSI = [
        'tessere:read',
        'tessere:write',
        'soci:read',
        'soci:write',
        'eventi:read',
        'eventi:write',
        'quote:read',
        '*',
    ];

    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can('create', ApiKey::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'scadenza' => ['nullable', 'date'],
            'permessi' => ['nullable', 'array'],
            'permessi.*' => ['string', Rule::in(self::PERMESSI)],
            'globale' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nome.required' => 'Il nome della chiave è obbligatorio.',
            'permessi.*.in' => 'Permesso non valido.',
        ];
    }
}
