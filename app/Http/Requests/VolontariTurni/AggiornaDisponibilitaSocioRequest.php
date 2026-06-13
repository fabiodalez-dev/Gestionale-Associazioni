<?php

namespace App\Http\Requests\VolontariTurni;

use App\Services\VolontariTurni\Disponibilita;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Il socio aggiorna in autonomia la propria disponibilità e il flag
 * volontario_attivo dall'area riservata (f.1 del piano "Organizzazione 3").
 * Le mansioni restano gestite dall'admin (competenze certificate).
 */
class AggiornaDisponibilitaSocioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('socio') !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'volontario_attivo' => ['nullable', 'boolean'],
            'disponibilita' => ['nullable', 'array'],
            'disponibilita.*' => ['array'],
            'disponibilita.*.*' => [Rule::in(array_keys(Disponibilita::FASCE))],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public function disponibilitaNormalizzata(): array
    {
        return Disponibilita::normalizza($this->input('disponibilita'));
    }
}
