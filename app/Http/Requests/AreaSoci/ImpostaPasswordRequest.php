<?php

namespace App\Http\Requests\AreaSoci;

use App\Models\Socio;
use App\Support\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

class ImpostaPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Politica password unica (PasswordPolicy) + conferma.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'confirmed', ...PasswordPolicy::rules($this->datiSocio())],
        ];
    }

    /**
     * Nome ed email del socio del link, per impedire password basate sui suoi dati.
     *
     * @return list<string|null>
     */
    private function datiSocio(): array
    {
        $token = (string) $this->route('token');
        $socio = $token === '' ? null : Socio::allTenants()
            ->where('password_reset_token', $token)
            ->where('password_reset_expires', '>', now())
            ->first();

        return $socio === null ? [] : [$socio->email, $socio->nome, $socio->cognome];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...PasswordPolicy::messages(),
            'password.confirmed' => 'Le password non corrispondono.',
        ];
    }
}
