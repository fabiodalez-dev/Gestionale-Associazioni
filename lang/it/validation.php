<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Messaggi di validazione (regole usate dal modulo auth)
    |--------------------------------------------------------------------------
    */

    'required' => 'Il campo :attribute è obbligatorio.',
    'email' => 'Il campo :attribute deve essere un indirizzo email valido.',
    'string' => 'Il campo :attribute deve essere una stringa.',
    'confirmed' => 'La conferma del campo :attribute non corrisponde.',
    'current_password' => 'La password non è corretta.',
    'uuid' => 'Il campo :attribute deve essere un UUID valido.',
    'min' => [
        'string' => 'Il campo :attribute deve contenere almeno :min caratteri.',
    ],
    'max' => [
        'string' => 'Il campo :attribute non può superare :max caratteri.',
    ],

    'attributes' => [
        'email' => 'email',
        'password' => 'password',
        'current_password' => 'password attuale',
        'code' => 'codice',
        'recovery_code' => 'codice di recupero',
        'associazione_id' => 'associazione',
    ],

];
