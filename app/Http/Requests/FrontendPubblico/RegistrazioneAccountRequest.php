<?php

namespace App\Http\Requests\FrontendPubblico;

use App\Models\Associazione;
use App\Support\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Registrazione di un account dal frontend pubblico (/a/{slug}/registrati).
 *
 * Email unica PER TENANT (unique(associazione_id, email)), password
 * min 10 con lettere+numeri confermata, consenso privacy obbligatorio.
 * Honeypot (spatie) e throttle 5/m sono middleware di rotta.
 */
class RegistrazioneAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:100'],
            'cognome' => ['required', 'string', 'max:100'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('accounts', 'email')
                    ->where('associazione_id', $this->associazioneCorrente()?->id),
            ],
            'telefono' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'confirmed', ...PasswordPolicy::rules([$this->input('email'), $this->input('nome'), $this->input('cognome')])],
            'privacy' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Esiste già un account registrato con questo indirizzo email.',
            'privacy.accepted' => 'Devi accettare l\'informativa sulla privacy per registrarti.',
            ...PasswordPolicy::messages(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nome' => 'nome',
            'cognome' => 'cognome',
            'email' => 'email',
            'telefono' => 'telefono',
            'password' => 'password',
            'privacy' => 'consenso privacy',
        ];
    }

    /**
     * Tenant corrente dal parametro di rotta {associazione:slug}.
     */
    public function associazioneCorrente(): ?Associazione
    {
        $associazione = $this->route('associazione');

        return $associazione instanceof Associazione ? $associazione : null;
    }
}
