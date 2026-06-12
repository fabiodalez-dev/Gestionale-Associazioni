<?php

namespace App\Http\Requests\EventiVita;

use App\Http\Requests\Eventi\EventoRequest;
use App\Services\EventiVita\SerieEventiService;
use Illuminate\Validation\Rule;

/**
 * Creazione di un evento ricorrente (f.8): tutti i campi dell'evento
 * (EventoRequest) più la regola di ricorrenza — frequenza settimanale o
 * mensile e data di fine serie. Il tetto di 52 occorrenze è applicato
 * dal SerieEventiService.
 */
class EventoRicorrenteRequest extends EventoRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'frequenza' => ['required', Rule::in(SerieEventiService::FREQUENZE)],
            'fino_al' => ['required', 'date', 'after:data_evento'],
        ];
    }

    /**
     * I dati del SOLO evento (la regola di ricorrenza viaggia a parte).
     *
     * @return array<string, mixed>
     */
    public function datiEvento(): array
    {
        $dati = $this->validated();

        unset($dati['frequenza'], $dati['fino_al']);

        return $dati;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return parent::attributes() + [
            'frequenza' => 'frequenza',
            'fino_al' => 'fine ricorrenza',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fino_al.after' => 'La fine della ricorrenza deve essere successiva alla data del primo evento.',
        ];
    }
}
