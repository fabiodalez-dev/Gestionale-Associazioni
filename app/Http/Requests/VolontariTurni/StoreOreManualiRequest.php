<?php

namespace App\Http\Requests\VolontariTurni;

use App\Models\Socio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Inserimento manuale ore volontariato "fuori turno" (f.5 del piano
 * "Organizzazione 3"): socio, ore, data e descrizione. Il socio deve
 * appartenere al tenant (verificato col global scope).
 */
class StoreOreManualiRequest extends FormRequest
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
            'socio_id' => ['required', 'uuid'],
            'ore' => ['required', 'numeric', 'min:0.25', 'max:24'],
            'data' => ['required', 'date', 'before_or_equal:today'],
            'descrizione' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            if (! Socio::query()->whereKey($this->input('socio_id'))->exists()) {
                $v->errors()->add('socio_id', 'Il socio selezionato non è valido.');
            }
        });
    }
}
