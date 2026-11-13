<?php

/*
|--------------------------------------------------------------------------
| Rotte API
|--------------------------------------------------------------------------
|
| Tutte le rotte /api/v1 sono registrate dai moduli (routes/modules/api-v1.php
| e routes/modules/eventi.php) con autenticazione AuthenticateApiV1 e
| throttle. La rotta di default /api/user (auth:sanctum) è stata rimossa:
| non era usata e restituiva l'intero record dell'utente autenticato.
|
*/
