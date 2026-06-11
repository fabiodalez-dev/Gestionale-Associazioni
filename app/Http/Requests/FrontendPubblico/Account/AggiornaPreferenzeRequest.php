<?php

namespace App\Http\Requests\FrontendPubblico\Account;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Preferenze json dell'account: newsletter, promemoria eventi, marketing.
 * Le checkbox non spuntate non arrivano nel payload → boolean nullable,
 * normalizzate a false nel controller.
 */
class AggiornaPreferenzeRequest extends FormRequest
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
            'newsletter' => ['nullable', 'boolean'],
            'promemoria_eventi' => ['nullable', 'boolean'],
            'marketing' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Preferenze normalizzate (sempre i tre flag, default false).
     *
     * @return array{newsletter: bool, promemoria_eventi: bool, marketing: bool}
     */
    public function preferenze(): array
    {
        return [
            'newsletter' => $this->boolean('newsletter'),
            'promemoria_eventi' => $this->boolean('promemoria_eventi'),
            'marketing' => $this->boolean('marketing'),
        ];
    }
}
