<?php

namespace App\Http\Requests\Membri;

use App\Models\Socio;
use App\Services\Membri\ModificaDatiService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Proposta di modifica dei dati anagrafici (f.19 — piano "Gestione 2"):
 * regole comuni alle due provenienze (area soci e area account).
 * Il socio target è risolto dalle sottoclassi in base alla guard.
 */
abstract class ProponiModificaDatiRequest extends FormRequest
{
    /**
     * Socio di cui si propongono le modifiche (NULL = non autorizzato).
     */
    abstract public function socioTarget(): ?Socio;

    public function authorize(): bool
    {
        return $this->socioTarget() !== null;
    }

    protected function prepareForValidation(): void
    {
        $cf = strtoupper(trim((string) $this->input('codice_fiscale', '')));

        $this->merge([
            'codice_fiscale' => $cf === '' ? null : $cf,
            'provincia' => strtoupper(trim((string) $this->input('provincia', ''))) ?: null,
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(ModificaDatiService $service): array
    {
        $socio = $this->socioTarget();

        return $socio !== null ? $service->rules($socio) : [];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return array_map('mb_strtolower', ModificaDatiService::LABELS);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Questa email appartiene già a un altro socio.',
            'codice_fiscale.unique' => 'Questo codice fiscale appartiene già a un altro socio.',
            'codice_fiscale.codice_fiscale' => 'Il codice fiscale non è valido.',
        ];
    }
}
