<?php

namespace App\Http\Requests\Admin;

use App\Models\Utente;
use Illuminate\Foundation\Http\FormRequest;

class PerformUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $utente = $this->user();

        return $utente instanceof Utente && $utente->isSuperAdmin();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // semver con eventuale pre-release (es. 0.2.0 o 1.0.0-rc.1)
            'versione' => ['required', 'string', 'max:30', 'regex:/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/'],
        ];
    }
}
