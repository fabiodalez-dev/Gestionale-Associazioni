<?php

namespace App\Http\Requests\Economia;

use App\Models\Donazione;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Donazione dalla pagina pubblica /a/{slug}/dona (f.15): importo libero
 * con minimo, donatore con email (per la ricevuta), anonimato opzionale.
 */
class DonaRequest extends FormRequest
{
    /** Importo minimo della donazione libera (f.15). */
    public const IMPORTO_MINIMO = 1;

    /** Tetto a protezione da errori di battitura. */
    public const IMPORTO_MASSIMO = 10000;

    public function authorize(): bool
    {
        // Pagina pubblica: nessuna autenticazione richiesta
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'importo' => ['required', 'numeric', 'min:'.self::IMPORTO_MINIMO, 'max:'.self::IMPORTO_MASSIMO],
            'donatore_nome' => ['nullable', 'string', 'max:150', 'required_unless:anonimo,1'],
            'donatore_email' => ['required', 'email:rfc', 'max:255'],
            'anonimo' => ['nullable', 'boolean'],
            'gateway' => ['required', Rule::in(['stripe', 'paypal'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'importo' => 'importo',
            'donatore_nome' => 'nome',
            'donatore_email' => 'email',
            'anonimo' => 'donazione anonima',
            'gateway' => 'metodo di pagamento',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'importo.min' => 'L\'importo minimo della donazione è € '.self::IMPORTO_MINIMO.'.',
            'donatore_nome.required_unless' => 'Inserisci il tuo nome (oppure scegli la donazione anonima).',
            'donatore_email.required' => 'L\'email è necessaria per inviarti la ricevuta.',
        ];
    }

    /**
     * Attributi della Donazione da creare (stato in_attesa).
     *
     * @return array{donatore_nome: string|null, donatore_email: string, importo: float, anonimo: bool, stato: string}
     */
    public function datiDonazione(): array
    {
        return [
            'donatore_nome' => $this->filled('donatore_nome') ? (string) $this->input('donatore_nome') : null,
            'donatore_email' => (string) $this->input('donatore_email'),
            'importo' => round((float) $this->input('importo'), 2),
            'anonimo' => $this->boolean('anonimo'),
            'stato' => Donazione::STATO_IN_ATTESA,
        ];
    }
}
