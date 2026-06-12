<?php

namespace App\Http\Requests\Piattaforma;

use App\Models\Accesso;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Filtri della pagina Audit accessi (f.25 del piano "Gestione 2"):
 * guard, esito, email (ricerca parziale) e periodo dal/al.
 */
class FiltriAuditAccessiRequest extends FormRequest
{
    /** @var list<string> */
    public const GUARDS = ['web', 'socio', 'account'];

    public function authorize(): bool
    {
        return Gate::allows('viewAny', Accesso::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'guard' => ['nullable', 'string', Rule::in(self::GUARDS)],
            'esito' => ['nullable', 'string', Rule::in(Accesso::ESITI)],
            'email' => ['nullable', 'string', 'max:255'],
            'dal' => ['nullable', 'date'],
            'al' => ['nullable', 'date', 'after_or_equal:dal'],
        ];
    }
}
