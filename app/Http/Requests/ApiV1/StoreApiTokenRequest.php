<?php

namespace App\Http\Requests\ApiV1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creazione personal access token Sanctum (miglioramento 23) dalla pagina
 * amministrativa. Le abilities usano la stessa sintassi dei permessi legacy.
 *
 * La scadenza è facoltativa ma mai oltre SCADENZA_MASSIMA_GIORNI: senza
 * indicazione il controller applica la scadenza massima.
 */
class StoreApiTokenRequest extends FormRequest
{
    /** Durata massima di un token (allineata a config sanctum.expiration). */
    public const SCADENZA_MASSIMA_GIORNI = 365;

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
            'scadenza' => [
                'nullable',
                'date',
                'after:today',
                'before_or_equal:'.now()->addDays(self::SCADENZA_MASSIMA_GIORNI)->toDateString(),
            ],
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
            'scadenza.before_or_equal' => 'La scadenza non può superare '.self::SCADENZA_MASSIMA_GIORNI.' giorni.',
        ];
    }
}
