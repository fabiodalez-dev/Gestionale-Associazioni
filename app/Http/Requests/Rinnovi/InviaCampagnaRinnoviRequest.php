<?php

namespace App\Http\Requests\Rinnovi;

use App\Models\Tessera;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Invio massivo della campagna rinnovi (f.2): selezione soci.
 * Autorizzazione: emissione tessere (TesseraPolicy::create).
 */
class InviaCampagnaRinnoviRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Tessera::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'socio_ids' => ['required', 'array', 'min:1'],
            'socio_ids.*' => ['uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'socio_ids.required' => 'Seleziona almeno un socio a cui inviare la campagna.',
            'socio_ids.min' => 'Seleziona almeno un socio a cui inviare la campagna.',
        ];
    }
}
