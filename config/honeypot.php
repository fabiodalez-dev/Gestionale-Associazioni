<?php

use App\Services\Struttura\FakeSuccessSpamResponder;

return [
    /*
     * Honeypot anti-spam sul form pubblico di preiscrizione (miglioramento 6).
     */
    'enabled' => env('HONEYPOT_ENABLED', true),

    /*
     * Nome del campo esca (il legacy usava `website_url`): qualsiasi richiesta
     * che lo invii valorizzato viene trattata come spam.
     */
    'name_field_name' => env('HONEYPOT_NAME', 'website_url'),

    'randomize_name_field_name' => env('HONEYPOT_RANDOMIZE', true),

    'valid_from_timestamp' => env('HONEYPOT_VALID_FROM_TIMESTAMP', true),

    'valid_from_field_name' => env('HONEYPOT_VALID_FROM', 'valid_from'),

    'amount_of_seconds' => (int) env('HONEYPOT_SECONDS', 1),

    /*
     * Ai bot viene mostrato un FINTO SUCCESSO senza scrivere nulla
     * (comportamento legacy preservato — spec struttura §6.4).
     */
    'respond_to_spam_with' => FakeSuccessSpamResponder::class,

    'honeypot_fields_required_for_all_forms' => false,

    'spam_protection' => \Spatie\Honeypot\SpamProtection::class,

    'with_csp' => env('HONEYPOT_WITH_CSP', false),
];
