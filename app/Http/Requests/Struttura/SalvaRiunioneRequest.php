<?php

namespace App\Http\Requests\Struttura;

use App\Models\Riunione;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creazione/modifica riunione del direttivo (f.17): titolo, data/ora,
 * luogo o spazio censito, ordine del giorno strutturato [{punto,
 * descrizione}] e stato. L'OdG arriva come array di righe dal form;
 * le righe senza testo vengono scartate in prepareForValidation.
 */
class SalvaRiunioneRequest extends FormRequest
{
    public function authorize(): bool
    {
        $riunione = $this->route('riunione');

        if ($riunione instanceof Riunione) {
            return $this->user()?->can('update', $riunione) ?? false;
        }

        return $this->user()?->can('create', Riunione::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('spazio_id') === '') {
            $this->merge(['spazio_id' => null]);
        }

        // Normalizza l'OdG: scarta le righe senza "punto" e riallinea gli indici
        $odg = $this->input('odg', []);

        if (is_array($odg)) {
            $puliti = [];

            foreach ($odg as $riga) {
                if (! is_array($riga)) {
                    continue;
                }

                $punto = trim((string) ($riga['punto'] ?? ''));

                if ($punto === '') {
                    continue;
                }

                $descrizione = trim((string) ($riga['descrizione'] ?? ''));

                $puliti[] = [
                    'punto' => $punto,
                    'descrizione' => $descrizione !== '' ? $descrizione : null,
                ];
            }

            $this->merge(['odg' => $puliti]);
        }
    }

    public function rules(): array
    {
        return [
            'titolo' => ['required', 'string', 'max:200'],
            'data_ora' => ['required', 'date'],
            'luogo' => ['nullable', 'string', 'max:200'],
            'spazio_id' => [
                'nullable',
                'uuid',
                Rule::exists('spazi', 'id')->where('associazione_id', session('associazione_id')),
            ],
            'stato' => ['required', Rule::in(Riunione::STATI)],
            'odg' => ['nullable', 'array'],
            'odg.*.punto' => ['required', 'string', 'max:200'],
            'odg.*.descrizione' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'spazio_id.exists' => 'Lo spazio selezionato non appartiene all\'associazione corrente.',
            'odg.*.punto.required' => 'Ogni punto dell\'ordine del giorno deve avere un titolo.',
        ];
    }
}
