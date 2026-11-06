<?php

namespace App\Http\Requests\Struttura;

use Illuminate\Validation\Rule;

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
        $id = $this->associazioneCorrente()?->id;

        // email NOT NULL + unique, partita IVA e codice fiscale unique
        // (vincoli della tabella associazioni, come UpdateAssociazioneRequest)
        return [
            'nome' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('associazioni', 'email')->ignore($id)],
            'partita_iva' => ['nullable', 'string', 'max:20', Rule::unique('associazioni', 'partita_iva')->ignore($id)],
            'codice_fiscale' => ['nullable', 'string', 'max:16', Rule::unique('associazioni', 'codice_fiscale')->ignore($id)],
            'telefono' => ['nullable', 'string', 'max:20'],
            'indirizzo' => ['nullable', 'string', 'max:500'],
            'citta' => ['nullable', 'string', 'max:100'],
            'provincia' => ['nullable', 'string', 'size:2', 'alpha'],
            'cap' => ['nullable', 'digits:5'],
        ];
    }
}
