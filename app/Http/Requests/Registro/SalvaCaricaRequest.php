<?php

namespace App\Http\Requests\Registro;

use App\Models\Carica;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creazione/modifica di una carica negli organi sociali (f.16).
 * Il socio deve appartenere al tenant corrente; fine mandato
 * (se indicata) non può precedere l'inizio.
 */
class SalvaCaricaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $carica = $this->route('carica');

        if ($carica instanceof Carica) {
            return $this->user()?->can('update', $carica) ?? false;
        }

        return $this->user()?->can('create', Carica::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'socio_id' => [
                'required',
                'uuid',
                Rule::exists('soci', 'id')
                    ->where('associazione_id', session('associazione_id'))
                    ->whereNull('deleted_at'),
            ],
            'ruolo' => ['required', 'string', 'max:100'],
            'inizio' => ['required', 'date'],
            'fine' => ['nullable', 'date', 'after_or_equal:inizio'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'socio_id.exists' => 'Il socio selezionato non appartiene all\'associazione corrente.',
            'fine.after_or_equal' => 'La fine del mandato non può precedere l\'inizio.',
        ];
    }
}
