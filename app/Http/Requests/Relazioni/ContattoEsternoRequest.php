<?php

namespace App\Http\Requests\Relazioni;

use App\Models\ContattoEsterno;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creazione/modifica contatto esterno del mini-CRM (f.21 del piano
 * "Organizzazione 3"): tipo, ragione sociale/nome, referente, recapiti,
 * note e tag liberi. Autorizzazione via ContattoEsternoPolicy nel controller.
 */
class ContattoEsternoRequest extends FormRequest
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
            'tipo' => ['required', Rule::in(ContattoEsterno::TIPI)],
            'nome' => ['required', 'string', 'max:200'],
            'referente' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:5000'],
            'tag' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Attributi pronti per il salvataggio: i tag arrivano come stringa
     * separata da virgole e diventano un array JSON normalizzato.
     *
     * @return array<string, mixed>
     */
    public function datiContatto(): array
    {
        return [
            'tipo' => $this->validated('tipo'),
            'nome' => $this->validated('nome'),
            'referente' => $this->validated('referente'),
            'email' => $this->validated('email'),
            'telefono' => $this->validated('telefono'),
            'note' => $this->validated('note'),
            'tag' => $this->tagNormalizzati(),
        ];
    }

    /**
     * @return list<string>
     */
    private function tagNormalizzati(): array
    {
        $raw = (string) ($this->validated('tag') ?? '');

        $tags = array_filter(array_map('trim', explode(',', $raw)), fn ($t) => $t !== '');

        // Deduplica preservando l'ordine (array_slice reindicizza), max 20 etichette
        return array_slice(array_unique($tags), 0, 20);
    }
}
