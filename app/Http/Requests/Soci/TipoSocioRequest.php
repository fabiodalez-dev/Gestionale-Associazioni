<?php

namespace App\Http\Requests\Soci;

use App\Models\Socio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tipi di socio (spec soci §3): nome obbligatorio e unico per associazione,
 * costo_tessera decimale facoltativo (vuoto → NULL, fallback su associazioni.costo_tessera).
 */
class TipoSocioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // autorizzazione via TipoSocioPolicy nel controller
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('costo_tessera') === '') {
            $this->merge(['costo_tessera' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nome' => [
                'required', 'string', 'max:255',
                Rule::unique('tipi_socio', 'nome')
                    ->where('associazione_id', Socio::currentAssociazioneId())
                    ->ignore($this->route('tipoSocio')?->id),
            ],
            'descrizione' => ['nullable', 'string'],
            'costo_tessera' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ];
    }
}
