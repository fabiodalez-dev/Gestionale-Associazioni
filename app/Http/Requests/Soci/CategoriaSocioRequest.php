<?php

namespace App\Http\Requests\Soci;

use App\Models\Socio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Categorie di socio (spec soci §4): nome unico per associazione
 * (vincolo presente nello schema reale), testo_tessera stampato sul PDF tessera.
 */
class CategoriaSocioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // autorizzazione via CategoriaSocioPolicy nel controller
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nome' => [
                'required', 'string', 'max:255',
                Rule::unique('categorie_socio', 'nome')
                    ->where('associazione_id', Socio::currentAssociazioneId())
                    ->ignore($this->route('categoriaSocio')?->id),
            ],
            'descrizione' => ['nullable', 'string'],
            'testo_tessera' => ['nullable', 'string'],
        ];
    }
}
