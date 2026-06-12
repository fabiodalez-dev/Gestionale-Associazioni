<?php

namespace App\Http\Requests\Membri;

use App\Models\Account;
use App\Models\Socio;

/**
 * Proposta di modifica dati dall'AREA ACCOUNT (guard 'account'):
 * consentita solo agli account collegati a un socio del tenant.
 */
class ProponiModificaDatiAccountRequest extends ProponiModificaDatiRequest
{
    public function socioTarget(): ?Socio
    {
        $account = $this->user('account');

        if (! $account instanceof Account || $account->socio_id === null) {
            return null;
        }

        return Socio::withoutGlobalScopes()
            ->where('associazione_id', $account->associazione_id)
            ->find($account->socio_id);
    }
}
