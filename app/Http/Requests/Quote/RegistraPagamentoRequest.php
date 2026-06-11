<?php

namespace App\Http\Requests\Quote;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegistraPagamentoRequest extends FormRequest
{
    public const METODI_OFFLINE = ['contanti', 'bonifico', 'carta', 'altro'];

    public const METODI_ONLINE = ['stripe', 'paypal'];

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
            'payment_method' => ['required', Rule::in([...self::METODI_OFFLINE, ...self::METODI_ONLINE])],
            // Obbligatoria solo per i metodi offline; per i metodi online viene generato un link
            'payment_date' => ['required_if:payment_method,'.implode(',', self::METODI_OFFLINE), 'nullable', 'date'],
        ];
    }

    public function isOnlineMethod(): bool
    {
        return in_array($this->input('payment_method'), self::METODI_ONLINE, true);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_method.required' => 'Seleziona un metodo di pagamento.',
            'payment_method.in' => 'Metodo di pagamento non valido.',
            'payment_date.required_if' => 'Inserisci la data di pagamento.',
        ];
    }
}
