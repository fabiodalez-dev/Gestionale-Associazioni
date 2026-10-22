<?php

/*
|--------------------------------------------------------------------------
| Piattaforma: registrazione autonoma delle associazioni
|--------------------------------------------------------------------------
| Modificabile dal super admin in Impostazioni di sistema → Registrazione.
|
| - disattivata: solo il super admin crea le associazioni
| - revisione:   chiunque può chiedere l'iscrizione, il super admin approva
| - automatica:  l'associazione e il suo amministratore nascono subito
*/

return [
    'registrazione_associazioni' => env('REGISTRAZIONE_ASSOCIAZIONI', 'disattivata'),

    // Destinatari dell'avviso di nuove richieste; vuoto = tutti i super admin attivi
    'registrazione_notifica_email' => env('REGISTRAZIONE_NOTIFICA_EMAIL'),
];
