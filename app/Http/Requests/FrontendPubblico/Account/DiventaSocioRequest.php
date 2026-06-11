<?php

namespace App\Http\Requests\FrontendPubblico\Account;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Richiesta "diventa socio" dall'area riservata: motivazione libera
 * che accompagna la notifica agli amministratori dell'associazione.
 */
class DiventaSocioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('account') !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'motivazione' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'motivazione' => 'motivazione',
        ];
    }
}
