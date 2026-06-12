<?php

namespace App\Http\Requests\EmailConferme;

use App\Models\Utente;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Invio di prova dal Centro anteprime email (f.20): consentito a chi
 * può inviare email (permesso spatie email.create, come le comunicazioni).
 */
class InviaProvaEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        $utente = $this->user();

        return $utente instanceof Utente && $utente->can('email.create');
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }
}
