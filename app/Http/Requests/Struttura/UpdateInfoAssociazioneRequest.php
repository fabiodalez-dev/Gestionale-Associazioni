<?php

namespace App\Http\Requests\Struttura;

/**
 * Dati anagrafici del tenant (spec §4.2.3). Qui NON si toccano
 * attiva e costo_tessera (riservati al super_admin in Gestione Associazioni).
 */
class UpdateInfoAssociazioneRequest extends ConfigurazioniRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'partita_iva' => ['nullable', 'string', 'max:20'],
            'codice_fiscale' => ['nullable', 'string', 'max:16'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'indirizzo' => ['nullable', 'string', 'max:500'],
            'citta' => ['nullable', 'string', 'max:100'],
            'provincia' => ['nullable', 'string', 'size:2', 'alpha'],
            'cap' => ['nullable', 'digits:5'],
        ];
    }
}
