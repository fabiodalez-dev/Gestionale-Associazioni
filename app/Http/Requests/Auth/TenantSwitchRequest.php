<?php

namespace App\Http\Requests\Auth;

use App\Models\Utente;
use Illuminate\Foundation\Http\FormRequest;

class TenantSwitchRequest extends FormRequest
{
    /**
     * Solo il super_admin può cambiare il contesto associazione.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof Utente && $user->isSuperAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Vuoto = vista globale (nessun tenant selezionato)
            'associazione_id' => ['nullable', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'associazione_id.uuid' => 'Associazione non valida.',
        ];
    }
}
