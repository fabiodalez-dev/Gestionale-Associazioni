<?php

namespace App\Http\Requests\Comunicazione;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Creazione/aggiornamento annuncio in bacheca (f.7): titolo, corpo HTML
 * (purificato nel controller via EmailHtmlSanitizer), finestra di
 * pubblicazione opzionale, evidenza e visibilità solo-soci.
 * L'autorizzazione (email.create/update) è nella AnnuncioPolicy.
 */
class AnnuncioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'in_evidenza' => $this->boolean('in_evidenza'),
            'solo_soci' => $this->boolean('solo_soci'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'titolo' => ['required', 'string', 'max:255'],
            'corpo_html' => ['required', 'string', 'max:65000'],
            'pubblicato_dal' => ['nullable', 'date'],
            'pubblicato_al' => ['nullable', 'date', 'after_or_equal:pubblicato_dal'],
            'in_evidenza' => ['required', 'boolean'],
            'solo_soci' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'titolo' => 'titolo',
            'corpo_html' => 'contenuto',
            'pubblicato_dal' => 'pubblicato dal',
            'pubblicato_al' => 'pubblicato fino al',
        ];
    }
}
