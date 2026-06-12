<?php

namespace App\Http\Requests\EventiVita;

use App\Models\FeedbackEvento;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Compilazione del form pubblico di feedback post-evento (f.11):
 * voto 1–5 obbligatorio, commento facoltativo.
 */
class FeedbackCompilaRequest extends FormRequest
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
            'voto' => ['required', 'integer', 'min:'.FeedbackEvento::VOTO_MIN, 'max:'.FeedbackEvento::VOTO_MAX],
            'commento' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'voto' => 'voto',
            'commento' => 'commento',
        ];
    }
}
