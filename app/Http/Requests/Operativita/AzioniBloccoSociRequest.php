<?php

namespace App\Http\Requests\Operativita;

use App\Models\CategoriaSocio;
use App\Models\Sede;
use App\Models\Socio;
use App\Models\Tag;
use App\Models\TipoSocio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Azioni in blocco sulla lista soci (f.2 del piano "Gestione 2"):
 * valida l'azione richiesta, il valore associato (esistente nel tenant
 * corrente grazie ai global scope) e la selezione di soci.
 */
class AzioniBloccoSociRequest extends FormRequest
{
    public const AZIONI = [
        'stato',
        'tipo_socio',
        'categoria',
        'sede',
        'tag_assegna',
        'tag_rimuovi',
    ];

    public function authorize(): bool
    {
        return true; // autorizzazione via SocioPolicy nel controller
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'azione' => ['required', 'string', Rule::in(self::AZIONI)],
            'valore' => ['required', 'string', 'max:255'],
            'soci_ids' => ['required', 'array', 'min:1', 'max:500'],
            'soci_ids.*' => ['required', 'uuid'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $azione = (string) $this->input('azione');
            $valore = (string) $this->input('valore');

            // I find() passano dal global scope tenant: un id valido di un
            // altro tenant risulta inesistente (isolamento multi-tenant).
            $valido = match ($azione) {
                'stato' => in_array($valore, Socio::STATI, true),
                'tipo_socio' => TipoSocio::query()->whereKey($valore)->exists(),
                'categoria' => CategoriaSocio::query()->whereKey($valore)->exists(),
                'sede' => Sede::query()->whereKey($valore)->exists(),
                'tag_assegna', 'tag_rimuovi' => Tag::query()->whereKey($valore)->exists(),
                default => false,
            };

            if (! $valido) {
                $v->errors()->add('valore', 'Il valore selezionato non è valido per questa azione.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'azione' => 'azione',
            'valore' => 'valore',
            'soci_ids' => 'soci selezionati',
        ];
    }
}
