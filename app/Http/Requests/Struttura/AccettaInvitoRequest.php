<?php

namespace App\Http\Requests\Struttura;

use App\Models\Utente;
use App\Support\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Accettazione invito amministratore: impostazione della password
 * tramite link firmato ricevuto via email.
 */
class AccettaInvitoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // la validità del link è garantita dal middleware 'signed'
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'confirmed', ...PasswordPolicy::rules($this->datiUtenteInvitato())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.required' => 'Inserisci la nuova password.',
            ...PasswordPolicy::messages(),
        ];
    }

    /**
     * Dati dell'amministratore invitato, per impedire password basate su nome ed email.
     *
     * @return list<string|null>
     */
    private function datiUtenteInvitato(): array
    {
        $utente = $this->route('utente');

        return $utente instanceof Utente ? [$utente->email, $utente->nome, $utente->cognome] : [];
    }
}
