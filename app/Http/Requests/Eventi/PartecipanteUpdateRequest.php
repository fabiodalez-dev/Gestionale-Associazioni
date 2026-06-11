<?php

namespace App\Http\Requests\Eventi;

use App\Models\EventoPartecipante;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Aggiornamento stato/note di un partecipante.
 * Lo stato è validato contro la whitelist (ex ENUM MySQL), cosa che il legacy
 * delegava al DB.
 */
class PartecipanteUpdateRequest extends FormRequest
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
        return [
            'stato_partecipazione' => ['required', Rule::in(EventoPartecipante::STATI)],
            'note' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'stato_partecipazione.in' => 'Stato di partecipazione non valido.',
        ];
    }
}
