<?php

namespace App\Http\Requests\EventiVita;

use App\Models\Evento;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Aggiornamento dell'INTERA serie ricorrente (f.8): solo i campi comuni
 * (SerieEventiService::CAMPI_SERIE) — mai le date, che restano proprie
 * di ogni occorrenza. L'autorizzazione (eventi.update) è nella policy.
 */
class SerieUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('prezzo')) {
            // Virgola decimale italiana, come EventoRequest
            $this->merge(['prezzo' => str_replace(',', '.', trim((string) $this->input('prezzo')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'titolo' => ['required', 'string', 'max:255'],
            'descrizione' => ['nullable', 'string'],
            'luogo' => ['nullable', 'string', 'max:255'],
            'stato_pubblicazione' => ['required', Rule::in(Evento::STATI_PUBBLICAZIONE)],
            'capienza' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'prezzo' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'iscrizioni_aperte' => ['required', 'boolean'],
            'richiede_approvazione' => ['required', 'boolean'],
            'solo_soci' => ['required', 'boolean'],
            'immagine_url' => ['nullable', 'string', 'url:http,https', 'max:2048'],
        ];
    }
}
