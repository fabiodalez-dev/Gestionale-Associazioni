<?php

namespace App\Http\Requests\Struttura;

/**
 * Sincronizzazione Google Calendar della pagina configurazioni.
 * Credenziali: file JSON del service account o testo incollato;
 * entrambi vuoti = credenziali invariate.
 */
class GoogleCalendarRequest extends ConfigurazioniRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'attiva' => ['nullable', 'boolean'],
            'calendar_id' => ['nullable', 'required_if:attiva,1', 'string', 'max:255', 'regex:/^[A-Za-z0-9._%+\-#@]+$/'],
            'credenziali_file' => ['nullable', 'file', 'max:20', 'mimetypes:application/json,text/plain'],
            'credenziali_json' => ['nullable', 'string', 'max:20000'],
            'rimuovi_credenziali' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'calendar_id.required_if' => 'Indica l\'ID del calendario per attivare la sincronizzazione.',
            'calendar_id.regex' => 'ID calendario non valido (es. abc123@group.calendar.google.com).',
            'credenziali_file.mimetypes' => 'Carica il file .json della chiave del service account.',
        ];
    }
}
