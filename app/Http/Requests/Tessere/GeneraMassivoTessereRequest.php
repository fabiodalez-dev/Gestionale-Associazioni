<?php

namespace App\Http\Requests\Tessere;

use App\Models\Tessera;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GeneraMassivoTessereRequest extends FormRequest
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
        $associazioneId = (string) session('associazione_id');

        return [
            'anno_validita' => ['required', 'integer', 'between:2000,2100'],
            'evento_creazione_id' => [
                'nullable',
                'uuid',
                Rule::exists('eventi', 'id')
                    ->where('associazione_id', $associazioneId)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'evento_creazione_id.exists' => 'Evento non valido per questa associazione.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->evento_creazione_id === '') {
            $this->merge(['evento_creazione_id' => null]);
        }
    }
}
