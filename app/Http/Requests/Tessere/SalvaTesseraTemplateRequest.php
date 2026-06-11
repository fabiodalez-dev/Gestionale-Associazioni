<?php

namespace App\Http\Requests\Tessere;

use App\Models\TesseraTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Salvataggio (creazione o modifica) di un template tessera.
 * Vincolo UNIQUE(associazione_id, tipo_socio_id) gestito anche per
 * tipo_socio_id NULL (template generico).
 */
class SalvaTesseraTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
            || $user->can('tessere.update');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $associazioneId = (string) session('associazione_id');

        return [
            'tipo_socio_id' => [
                'nullable',
                'uuid',
                Rule::exists('tipi_socio', 'id')->where('associazione_id', $associazioneId),
            ],
            'titolo' => ['nullable', 'string', 'max:255'],
            'contenuto' => ['required', 'string'],
            'attivo' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $associazioneId = (string) session('associazione_id');
            $tipoSocioId = $this->input('tipo_socio_id') ?: null;
            $corrente = $this->route('template');

            $query = TesseraTemplate::allTenants()
                ->where('associazione_id', $associazioneId);

            $tipoSocioId === null
                ? $query->whereNull('tipo_socio_id')
                : $query->where('tipo_socio_id', $tipoSocioId);

            if ($corrente instanceof TesseraTemplate) {
                $query->whereKeyNot($corrente->id);
            }

            if ($query->exists()) {
                $v->errors()->add('tipo_socio_id', 'Esiste già un template per questo tipo socio.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'tipo_socio_id' => $this->tipo_socio_id ?: null,
            'attivo' => $this->boolean('attivo'),
        ]);
    }
}
