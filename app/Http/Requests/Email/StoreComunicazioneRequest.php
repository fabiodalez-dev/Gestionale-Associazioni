<?php

namespace App\Http\Requests\Email;

use App\Models\EmailQueue;
use App\Services\Email\RecipientSelector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreComunicazioneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('send', EmailQueue::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:500'],
            'corpo_html' => ['required', 'string'],
            'recipient_type' => ['required', Rule::in(RecipientSelector::TYPES)],
            'recipient_filter_id' => [
                Rule::requiredIf(fn () => in_array($this->input('recipient_type'), ['category', 'section', 'tag', 'gruppo'], true)),
                'nullable',
                'uuid',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'subject.required' => 'Oggetto e corpo email sono obbligatori.',
            'corpo_html.required' => 'Oggetto e corpo email sono obbligatori.',
            'recipient_filter_id.required' => 'Seleziona il filtro destinatari.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('subject'))) {
            $this->merge([
                'subject' => preg_replace('/[\x00-\x1F\x7F]/u', '', $this->input('subject')),
            ]);
        }
    }
}
