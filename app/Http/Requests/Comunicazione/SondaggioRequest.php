<?php

namespace App\Http\Requests\Comunicazione;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Creazione/aggiornamento sondaggio (f.11): domanda + opzioni (textarea,
 * una per riga → array json), finestra di apertura e flag solo_soci.
 * L'autorizzazione (email.create/update) è nella SondaggioPolicy.
 */
class SondaggioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Textarea "una opzione per riga" → lista pulita (righe vuote escluse)
        $opzioni = preg_split('/\r\n|\r|\n/', (string) $this->input('opzioni_testo', ''));
        $opzioni = array_values(array_filter(array_map('trim', $opzioni ?: []), fn ($o) => $o !== ''));

        $this->merge([
            'opzioni' => $opzioni,
            'solo_soci' => $this->boolean('solo_soci'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'domanda' => ['required', 'string', 'max:500'],
            'opzioni' => ['required', 'array', 'min:2', 'max:20'],
            'opzioni.*' => ['required', 'string', 'max:255'],
            'aperto_dal' => ['nullable', 'date'],
            'aperto_al' => ['nullable', 'date', 'after_or_equal:aperto_dal'],
            'solo_soci' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'opzioni.required' => 'Inserisci almeno due opzioni di risposta (una per riga).',
            'opzioni.min' => 'Inserisci almeno due opzioni di risposta (una per riga).',
            'opzioni.max' => 'Massimo 20 opzioni di risposta.',
        ];
    }
}
