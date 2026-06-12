<?php

namespace App\Http\Requests\Registro;

use App\Models\Cessazione;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Registrazione di una cessazione formale (f.15): tipo, data, motivo
 * ed eventuale delibera. Il socio deve appartenere al tenant corrente.
 * L'autorizzazione fine è della CessazionePolicy nel controller.
 */
class StoreCessazioneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Cessazione::class) ?? false;
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
            'tipo' => ['required', Rule::in(Cessazione::TIPI)],
            'data' => ['required', 'date'],
            'motivo' => ['nullable', 'string', 'max:2000'],
            'delibera_numero' => ['nullable', 'string', 'max:50'],
            'delibera_data' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'socio_id.exists' => 'Il socio selezionato non appartiene all\'associazione corrente.',
            'tipo.in' => 'Tipo di cessazione non valido.',
        ];
    }
}
