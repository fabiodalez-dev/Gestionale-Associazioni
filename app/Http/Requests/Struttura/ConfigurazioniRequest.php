<?php

namespace App\Http\Requests\Struttura;

use App\Models\Associazione;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base per tutte le azioni della pagina configurazioni: autorizza
 * super_admin (tenant selezionato dal middleware 'tenant') e
 * admin_associazione sul proprio tenant. L'operatore è escluso.
 */
abstract class ConfigurazioniRequest extends FormRequest
{
    public function authorize(): bool
    {
        $associazione = $this->associazioneCorrente();

        return $associazione !== null
            && ($this->user()?->can('manageSettings', $associazione) ?? false);
    }

    public function associazioneCorrente(): ?Associazione
    {
        $id = session('associazione_id');

        return $id ? Associazione::find($id) : null;
    }
}
