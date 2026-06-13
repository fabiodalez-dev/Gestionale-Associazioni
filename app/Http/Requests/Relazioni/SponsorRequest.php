<?php

namespace App\Http\Requests\Relazioni;

use App\Models\ContattoEsterno;
use App\Models\Sponsor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creazione/modifica sponsor/partner (f.22 del piano "Organizzazione 3"):
 * livello, periodo di validità, visibilità sul mini-sito e collegamento
 * opzionale a una scheda del mini-CRM. Autorizzazione via SponsorPolicy.
 */
class SponsorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:200'],
            'livello' => ['required', Rule::in(Sponsor::LIVELLI)],
            'logo_url' => ['nullable', 'url', 'max:500'],
            'sito_url' => ['nullable', 'url', 'max:500'],
            'dal' => ['nullable', 'date'],
            'al' => ['nullable', 'date', 'after_or_equal:dal'],
            'visibile_sul_sito' => ['nullable', 'boolean'],
            'contatto_esterno_id' => ['nullable', 'uuid'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            // Il contatto collegato deve appartenere al tenant corrente:
            // il global scope tenant fa sparire gli id di altri tenant.
            if ($this->filled('contatto_esterno_id')
                && ! ContattoEsterno::query()->whereKey($this->input('contatto_esterno_id'))->exists()) {
                $v->errors()->add('contatto_esterno_id', 'Il contatto selezionato non è valido.');
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function datiSponsor(): array
    {
        return [
            'nome' => $this->validated('nome'),
            'livello' => $this->validated('livello'),
            'logo_url' => $this->validated('logo_url'),
            'sito_url' => $this->validated('sito_url'),
            'dal' => $this->validated('dal'),
            'al' => $this->validated('al'),
            'visibile_sul_sito' => $this->boolean('visibile_sul_sito'),
            'contatto_esterno_id' => $this->validated('contatto_esterno_id'),
        ];
    }
}
