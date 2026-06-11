<?php

namespace App\Http\Requests\FrontendPubblico\Account;

use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cambio email dall'area riservata: la nuova email deve essere unica
 * PER TENANT (unique(associazione_id, email), soft-deleted inclusi come
 * da vincolo DB). Il cambio azzera la verifica e reinvia il link.
 */
class CambiaEmailRequest extends FormRequest
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
        /** @var Account $account */
        $account = $this->user('account');

        return [
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('accounts', 'email')
                    ->where('associazione_id', $account->associazione_id)
                    ->ignore($account->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Esiste già un account registrato con questo indirizzo email.',
        ];
    }
}
