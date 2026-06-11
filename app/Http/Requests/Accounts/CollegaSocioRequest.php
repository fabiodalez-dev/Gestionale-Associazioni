<?php

namespace App\Http\Requests\Accounts;

use App\Models\Account;
use App\Models\Socio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Collegamento esplicito account ↔ socio (azione admin).
 *
 * Vincoli del piano (anti account-takeover):
 * - mai automatico: il socio è scelto esplicitamente dall'admin
 *   (il match per email è solo un suggerimento in UI);
 * - socio_id unique sugli accounts: un socio può essere collegato
 *   a UN solo account;
 * - il socio deve appartenere al tenant dell'account.
 */
class CollegaSocioRequest extends FormRequest
{
    public function authorize(): bool
    {
        // L'autorizzazione (AccountPolicy::gestisci) è nel controller
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'socio_id' => ['required', 'string', 'uuid'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('socio_id')) {
                return;
            }

            /** @var Account $account */
            $account = $this->route('account');
            $socioId = (string) $this->input('socio_id');

            if ($account->socio_id !== null) {
                $validator->errors()->add('socio_id', 'Questo account è già collegato a un socio: scollegalo prima di procedere.');

                return;
            }

            // Socio del tenant dell'account (404-equivalente per id altrui)
            $socio = Socio::query()
                ->forAssociazione($account->associazione_id)
                ->find($socioId);

            if ($socio === null) {
                $validator->errors()->add('socio_id', 'Socio non trovato in questa associazione.');

                return;
            }

            // socio_id unique: il socio non deve essere già collegato a un altro account
            $giaCollegato = Account::allTenants()
                ->withTrashed()
                ->where('socio_id', $socio->id)
                ->exists();

            if ($giaCollegato) {
                $validator->errors()->add('socio_id', 'Questo socio è già collegato a un altro account.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'socio_id' => 'socio',
        ];
    }
}
