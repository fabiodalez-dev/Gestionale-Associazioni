<?php

namespace App\Http\Requests\Soci;

use App\Models\Socio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tag soci (spec soci §6): nome unico per associazione, colore esadecimale.
 */
class TagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // autorizzazione via TagPolicy nel controller
    }

    protected function prepareForValidation(): void
    {
        if (blank($this->input('colore'))) {
            $this->merge(['colore' => '#888888']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nome_tag' => [
                'required', 'string', 'max:50',
                Rule::unique('tags', 'nome_tag')
                    ->where('associazione_id', Socio::currentAssociazioneId())
                    ->ignore($this->route('tag')?->id),
            ],
            'colore' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
