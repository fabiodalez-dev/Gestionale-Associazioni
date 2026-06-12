<?php

namespace App\Http\Requests\Comunicazione;

use App\Models\EmailQueue;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Email diretta al socio dalla scheda (f.12): oggetto + corpo in testo
 * semplice (escapato e convertito in HTML nel controller). Autorizzazione
 * come l'invio delle comunicazioni massive: email.create
 * (EmailQueuePolicy::send).
 */
class MessaggioSocioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('send', EmailQueue::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'oggetto' => ['required', 'string', 'max:255'],
            'corpo' => ['required', 'string', 'max:20000'],
        ];
    }
}
