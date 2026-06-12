<?php

namespace App\Http\Requests\Operativita;

use App\Models\Evento;
use App\Models\Socio;
use App\Models\Utente;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creazione/modifica attività admin (f.4 del piano "Gestione 2"):
 * titolo, descrizione, scadenza, assegnatario (utente del tenant) e
 * collegamento polimorfico opzionale a un socio o a un evento.
 */
class StoreAttivitaAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // autorizzazione via AttivitaAdminPolicy nel controller
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'titolo' => ['required', 'string', 'max:255'],
            'descrizione' => ['nullable', 'string', 'max:5000'],
            'scadenza' => ['nullable', 'date'],
            'assegnatario_id' => ['nullable', 'uuid'],
            'collegamento' => ['nullable', 'string', Rule::in(['nessuno', 'socio', 'evento'])],
            'socio_id' => ['nullable', 'uuid', 'required_if:collegamento,socio'],
            'evento_id' => ['nullable', 'uuid', 'required_if:collegamento,evento'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            // Assegnatario: deve essere un utente attivo del tenant corrente
            // (Utente non ha global scope tenant → verifica esplicita).
            if ($this->filled('assegnatario_id')) {
                $tenantId = Socio::currentAssociazioneId();

                $valido = Utente::query()
                    ->whereKey($this->input('assegnatario_id'))
                    ->where('attivo', true)
                    ->when($tenantId !== null, fn ($q) => $q->where('associazione_id', $tenantId))
                    ->exists();

                if (! $valido) {
                    $v->errors()->add('assegnatario_id', 'L\'assegnatario selezionato non è valido.');
                }
            }

            // Collegamento polimorfico: il global scope tenant fa sparire
            // gli id di altri tenant (isolamento multi-tenant).
            if ($this->input('collegamento') === 'socio'
                && ! Socio::query()->whereKey($this->input('socio_id'))->exists()) {
                $v->errors()->add('socio_id', 'Il socio selezionato non è valido.');
            }

            if ($this->input('collegamento') === 'evento'
                && ! Evento::query()->whereKey($this->input('evento_id'))->exists()) {
                $v->errors()->add('evento_id', 'L\'evento selezionato non è valido.');
            }
        });
    }

    /**
     * Attributi pronti per il salvataggio (collegamento → morph notabile).
     *
     * @return array<string, mixed>
     */
    public function datiAttivita(): array
    {
        [$notabileType, $notabileId] = match ($this->input('collegamento')) {
            'socio' => [Socio::class, $this->input('socio_id')],
            'evento' => [Evento::class, $this->input('evento_id')],
            default => [null, null],
        };

        return [
            'titolo' => $this->validated('titolo'),
            'descrizione' => $this->validated('descrizione'),
            'scadenza' => $this->validated('scadenza'),
            'assegnatario_id' => $this->validated('assegnatario_id'),
            'notabile_type' => $notabileType,
            'notabile_id' => $notabileId,
        ];
    }
}
