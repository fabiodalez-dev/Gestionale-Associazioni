<?php

namespace App\Http\Requests\AreaSoci;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ImpostaPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Regola legacy: min 8 caratteri + conferma. Password::min(8) consente
     * di irrobustire centralmente in futuro (Password::defaults()).
     *
     * @return array<string, array<int, Rule|string>>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.confirmed' => 'Le password non corrispondono.',
            'password.min' => 'La password deve contenere almeno 8 caratteri.',
        ];
    }
}
