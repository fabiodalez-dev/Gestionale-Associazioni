<?php

namespace App\Http\Requests\FrontendPubblico;

use App\Support\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Registrazione di un account dal frontend pubblico (/a/{slug}/registrati).
 *
 * Email unica PER TENANT (unique(associazione_id, email), controllata dal
 * controller con risposta neutra), password
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
            // Niente "unique": un'email già registrata riceve la stessa risposta di
            // una nuova (RegistrazioneController), così il modulo non è un oracolo
            'email' => ['required', 'string', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:20'],
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
}
