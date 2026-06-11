<?php

namespace App\Http\Requests\FrontendPubblico;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Prenotazione di un evento dal frontend pubblico (guard 'account').
 *
 * Qui solo la forma del payload: il limite max_posti_per_prenotazione,
 * la finestra iscrizioni e la capienza dipendono dall'evento e vengono
 * verificati DENTRO il mutex per-evento da PrenotazioneService.
 */
class PrenotaEventoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('account') !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'posti' => ['required', 'integer', 'min:1', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'posti' => 'posti',
        ];
    }
}
