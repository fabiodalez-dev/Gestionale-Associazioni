# Changelog

Le modifiche rilevanti di ogni versione. Le versioni precedenti alla 0.3.2 sono descritte nelle [release su GitHub](https://github.com/fabiodalez-dev/Gestionale-Associazioni/releases). Il dettaglio operativo per chi aggiorna è in [docs/AGGIORNAMENTI.md](docs/AGGIORNAMENTI.md).

## [0.3.2] - da rilasciare

Versione di sicurezza: raccoglie le correzioni del terzo audit del codice. Nessuna correzione è critica o alta; due erano di gravità media, le altre basse. Contiene una migration e nessuna nuova variabile di configurazione.

### Da sapere prima di aggiornare

- **Chiavi API globali sospese.** Prima della 0.3.1, eliminando un'associazione le sue chiavi API restavano senza associazione e diventavano globali, con accesso ai dati di tutte le associazioni. La 0.3.1 le ha marcate come globali insieme a quelle create apposta dal super admin, e nei dati non c'è modo di distinguerle. L'aggiornamento disattiva quindi tutte le chiavi globali presenti: il super admin riattiva dalla pagina Chiavi API solo quelle che riconosce. Fino ad allora un'integrazione che usa una chiave globale riceve 403 `key_disabled`. Le chiavi delle singole associazioni non cambiano.
- **Conferma pagamento delle pre-iscrizioni.** Il pulsante "Conferma pagamento" sui soci in attesa di pagamento richiede ora anche il permesso di modifica sulle quote, perché crea quota pagata, ricevuta e movimento di prima nota. Il ruolo Segretario predefinito riceve le quote in modifica e continua a incassare allo sportello come prima (di conseguenza può registrare anche il pagamento manuale di una quota dalla pagina Quote); i ruoli personalizzati con i soli permessi sui soci non vedono più il pulsante.
- **Documenti dei soci.** Il modulo Documenti dei soci richiede anche l'autorità sul socio: permesso di vedere i soci per elencare e scaricare, di modificarli per aggiungere. Il Volontario non vi accede più, il Tesoriere non può più caricare. Eliminare un documento dalla scheda socio richiede il permesso di eliminazione sui documenti. I ruoli personalizzati vanno ricontrollati.
- **Token API personali.** Dopo un recupero o cambio password, o una revoca delle sessioni, i token API dell'utente vengono eliminati e vanno rigenerati.

### Sicurezza

- Perimetro per sede rispettato da prenotazioni degli spazi, registro modifiche, scioglimento dei nuclei e riassegnazione delle tessere: chi è limitato a una sede non vede né modifica i dati delle altre.
- Le sessioni di account pubblici e soci si chiudono quando cambia la password o l'email viene verificata; anche il cookie "ricordami" decade.
- Il link "imposta password" dell'area soci parte al massimo una volta ogni 10 minuti verso lo stesso socio, con risposta identica per tutti.
- Il limite dei tentativi di accesso del pannello conta insieme le varianti accentate dello stesso indirizzo email.
- Un account pubblico con l'email non verificata non può inviare richieste di iscrizione; il cambio email dell'account non rivela più se un indirizzo è già registrato.
- L'avviso di accessi falliti ai super admin non rende più cliccabile il testo digitato da chi tenta l'accesso.
- La verifica pubblica della tessera tiene conto dello stato del socio: chi non è più attivo risulta "Socio non attivo", senza indicarne il motivo.
- Un webhook PayPal senza gli header di firma viene rifiutato prima di qualsiasi chiamata a PayPal con le credenziali dell'associazione.

### Correzioni

- Se l'elaborazione di un evento Stripe fallisce con un errore, il nuovo tentativo di Stripe viene elaborato invece di essere scartato come duplicato.
- Eliminare definitivamente un socio, anche con `cestino:svuota`, elimina i suoi documenti insieme ai file; `cestino:svuota` rimuove anche i documenti dei soci rimasti nel cestino oltre la soglia.
- Un nucleo che comprende un socio nel cestino si può sciogliere di nuovo.
- Le notifiche in coda usano subito le impostazioni SMTP aggiornate dell'associazione, senza riavviare il worker.

### Dipendenze

- Laravel 13.42, che risolve l'avviso di sicurezza sul Concurrency Process segnalato da `composer audit`.
