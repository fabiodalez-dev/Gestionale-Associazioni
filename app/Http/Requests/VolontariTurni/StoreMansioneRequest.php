<?php

namespace App\Http\Requests\VolontariTurni;

use App\Models\Mansione;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creazione/modifica mansione (f.2 del piano "Organizzazione 3"): nome
 * univoco per tenant e descrizione. Autorizzazione via MansionePolicy
 * nel controller.
 */
class StoreMansioneRequest extends FormRequest
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
        $mansione = $this->route('mansione');
        $tenantId = Mansione::currentAssociazioneId();

        return [
            'nome' => [
                'required', 'string', 'max:100',
                Rule::unique('mansioni', 'nome')
                    ->where(fn ($q) => $q->where('associazione_id', $tenantId))
                    ->ignore($mansione instanceof Mansione ? $mansione->id : null),
            ],
            'descrizione' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nome.unique' => 'Esiste già una mansione con questo nome.',
        ];
    }
}
