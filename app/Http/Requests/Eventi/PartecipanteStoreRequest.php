<?php

namespace App\Http\Requests\Eventi;

use App\Models\Evento;
use App\Models\EventoPartecipante;
use App\Models\Socio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Aggiunta di un singolo socio a un evento.
 *
 * A differenza del legacy (che si fidava della UI), qui viene verificato
 * server-side che il socio esista, sia Attivo e appartenga allo stesso
 * tenant dell'evento.
 */
class PartecipanteStoreRequest extends FormRequest
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
        /** @var Evento $evento */
        $evento = $this->route('evento');

        return [
            'socio_id' => [
                'required',
                'uuid',
                Rule::exists('soci', 'id')
                    ->where('associazione_id', $evento->associazione_id)
                    ->where('stato', Socio::STATO_ATTIVO)
                    ->whereNull('deleted_at'),
            ],
            'stato_partecipazione' => ['required', Rule::in(EventoPartecipante::STATI)],
            'note' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'socio_id.required' => 'Seleziona un socio.',
            'socio_id.exists' => 'Il socio selezionato non è valido o non è attivo.',
            'stato_partecipazione.in' => 'Stato di partecipazione non valido.',
        ];
    }
}
