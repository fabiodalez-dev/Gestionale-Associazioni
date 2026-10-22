# Report Piattaforma 4

Esito delle fasi di [PIANO-PIATTAFORMA-4.md](PIANO-PIATTAFORMA-4.md), aggiornato a ogni commit.

## Preliminare — recupero password, policy password, impostazioni di sistema

Commit `2c30c50`. Recupero password admin con Fortify, `PasswordPolicy` con zxcvbn per tutti i tipi di utente, pagina Impostazioni di sistema per il `.env`. 1732 test verdi.

Correzioni di sicurezza successive (revisione automatica del commit):
- il link di reset era costruito dall'Host della richiesta (password reset poisoning): ora le notifiche di reset di admin e account pubblici usano `APP_URL`, e fuori dall'ambiente locale `AppServiceProvider` forza `APP_URL` come radice di tutti gli URL generati. `trustHosts` non è stato attivato perché bloccherebbe l'installer prima che `APP_URL` venga scritto;
- l'email di reset degli admin poteva passare dall'SMTP dell'associazione, configurato dal suo admin: ora usa solo il mailer di piattaforma. Il reset degli account pubblici continua a usare l'SMTP del tenant (stesso perimetro dell'associazione che gestisce già quei dati).

## Fase 0 — PHP 8.5 con Laravel Excel 4

- `maatwebsite/excel` 3.1.69 → 4.0.5, `phpoffice/phpspreadsheet` 1.30.5 → 5.10.0 (aggiornate solo le dipendenze di Excel, non il framework).
- Adattamenti richiesti dalla 4.x: tipi di ritorno su `query()` e `collection()`, `map(mixed $row)`, interfacce marker `Import`/`Export` sulle classi che non usano un concern `From*`/`To*` (`RigheAggiornamentoImport`, `AnagraficaProExport`), tipo corretto delle chiavi delle righe importate (`int|string`: le colonne senza intestazione restano numeriche).
- Nuovo test di round-trip: l'xlsx esportato, reimportato senza modifiche, non produce differenze né errori.
- Requisiti: installer a PHP 8.3 minimo (come composer.json), README aggiornato a Laravel 13 / PHP 8.5.
- Esito: **1734/1734 test verdi su PHP 8.5.12**, PHPStan livello 5 pulito su tutta l'app, server di sviluppo avviato con PHP 8.5.

## Fase 1 — Ruoli dell'associazione

- Grant scartato per maturità (v0.0.1); team di spatie non attivati (vedi piano): ruoli globali, confine tra associazioni garantito dal tenant.
- `App\Support\RuoliAssociazione`: 18 moduli, 72 permessi, 9 ruoli (super_admin, admin_associazione, operatore + presidente, vicepresidente, segretario, tesoriere, consigliere, volontario). `RolesSeeder` ne deriva ruoli e permessi.
- 18 policy rimappate sui moduli dedicati (economia, iscrizioni, volontari, spazi, progetti, riunioni, relazioni, comunicazione) con fallback per ruolo invariati; menu laterale con controlli per singola voce.
- Pagina `/ruoli` (Ruoli e permessi) per admin dell'associazione e super_admin; policy `gestisciRuoli` / `assegnaRuoli` (solo operatori dell'associazione corrente).
- Listener di login e modifica utente dal super_admin conservano le cariche; i ruoli di sistema scaduti vengono rimossi.
- `app:aggiorna-permessi` eseguito dall'updater dopo `migrate`; già applicato al DB di sviluppo.
- Esito: **1741/1741 test verdi** (7 nuovi in `RuoliTest`, tra cui la verifica che l'operatore mantenga esattamente i permessi precedenti), PHPStan pulito, verifica visiva della pagina.

Correzione di sicurezza (commit `7fe4cea`): l'SMTP dell'associazione è modificabile solo dall'admin dell'associazione e dal super_admin. Le cariche con `email.update` inviano comunicazioni e gestiscono i template, ma non cambiano il server di posta, che permetterebbe di leggere tutte le email in uscita.

## Fase 2 — Formello sui form di iscrizione

- Base `App\Forms\ModuloFormello`: i form si rendono solo come campi (`@include('formello.campi', ['form' => …, 'solo' => […]])`) dentro il `<form>` della pagina, che mantiene azione, pulsanti e stile; le regole restano nelle FormRequest. Supporta un contesto (associazione, elenchi di scelta, campi obbligatori) e le error bag con nome (Fortify `updatePassword`).
- Form: `RegistrazioneAccountForm`, `PreiscrizioneForm` (campi obbligatori configurabili, rinnovo precompilato; i campi personalizzati restano in Blade), `AmministratoreForm`, `AssociazioneForm` (logo, collegamento admin esistente, sede e clonazione restano in Blade), `InstallerAdminForm`, `ImpostaPasswordForm` (invito admin, reset admin e account, primo accesso socio, cambio password da profilo admin e account).
- Template Bootstrap riscritti in `resources/views/vendor/formello`: stella sui campi obbligatori, attributi booleani corretti (Formello renderebbe `required=""` anche per i campi facoltativi), errore sotto il campo, testo di aiuto escapato, prefisso/suffisso.
- Widget dell'app: `password` (mostra/nascondi, valore mai ripopolato, indicatore di robustezza) e `consenso` (casella o interruttore, con o senza campo nascosto "0").
- Indicatore di robustezza: zxcvbn-ts 3 con dizionari comune e italiano, file statico `public/assets/vendor/zxcvbn/password-strength.min.js` (1,1 MB, 520 KB compresso, caricato solo dove c'è un campo password) perché `public/build` non arriva nelle installazioni aggiornate dall'updater. Rispecchia `PasswordPolicy`: lunghezza, punteggio minimo e divieto dei dati personali. Sorgente in `resources/js/vendor-src/password-strength.js`; per rigenerarlo: `esbuild resources/js/vendor-src/password-strength.js --bundle --minify --format=iife --target=es2018 --outfile=public/assets/vendor/zxcvbn/password-strength.min.js` con `@zxcvbn-ts/core`, `@zxcvbn-ts/language-common` e `@zxcvbn-ts/language-it` 3.x installati.
- Asset JS di Formello disattivati (nessun widget li usa); layout dell'installer con `@stack('scripts')`.
- Esito: **1748/1748 test verdi** (6 nuovi in `FormelloFormsTest`: password mai ripopolata, soglie della policy esposte al client, campi obbligatori e facoltativi senza `required` spurio, rinnovo precompilato, errori sotto il campo, form amministratore in creazione e modifica), PHPStan pulito, **E2E 109/109** dopo l'aggiornamento di un selettore (il messaggio d'errore della password ora sta fuori dall'`input-group` del pulsante mostra/nascondi).

Correzione di sicurezza (Fase 2): Formello registra di default la rotta `POST /formello/compute` per i callback reattivi; con l'allowlist vuota istanzia qualsiasi classe indicata dal client e carica qualsiasi modello per id. Nessun form la usa: `compute_path` è ora `null` e un test verifica che la rotta non esista.

## Fase 3 — Impostazioni per associazione con spatie/laravel-settings

- Tabella `settings` con `associazione_id` (NULL = piattaforma), unicità su associazione + gruppo + nome, FK con cancellazione a cascata.
- `TenantSettingsRepository` (repository `associazione` in `config/settings.php`): ogni query è filtrata per l'associazione di `ContestoImpostazioni` e fallisce se il contesto manca. Il contesto vale solo dentro una closure, quindi comandi e job che scorrono più associazioni non leggono mai quella sbagliata (la sessione non viene usata). Cache di spatie spenta per questo repository (la chiave è per classe e mescolerebbe le associazioni) e auto-discovery disattivata: le classi non si risolvono dal container.
- Base `App\Settings\ImpostazioniAssociazione` con `::per($associazione)` e `aggiorna([...])`; sul modello `$associazione->tesseramento()` e `$associazione->iscrizioni()`, memorizzate per istanza.
- `TesseramentoSettings`: tipo di scadenza, giorni di preavviso, template email di scadenza, step dei promemoria di rinnovo. `IscrizioniSettings`: apertura, modalità, schema del modulo pubblico, campi obbligatori. Valori normalizzati (tipo non valido → solare, preavviso 0 → 30, modalità non valida → preiscrizione).
- Transizione: lettura dalla riga salvata, altrimenti dalla colonna di `associazioni` (riletta dal DB se il modello è caricato con colonne parziali); scrittura su entrambe in transazione. Così query SQL, import legacy, factory dei test e codice non migrato restano coerenti; nessuna copia massiva dei valori. Tutte le letture e scritture di queste otto impostazioni (controller, servizi, comandi, viste, fixture E2E, modifica associazione del super_admin) passano dalle classi.
- Restano colonne, per scelta: `costo_tessera` (usato in SUM/COALESCE SQL con i tipi socio), `auguri_automatici` (filtro `where` del comando auguri), `frontend_pubblico_attivo` (risoluzione del tenant pubblico), `descrizione_pubblica` e `privacy_policy_html` (contenuti, non configurazione). Pagamenti e SMTP restano nelle tabelle dedicate con credenziali cifrate. Il piano prevedeva anche Anagrafica/Pagamenti/Comunicazioni/Aspetto: li ho esclusi perché sono dati interrogati in SQL o già in tabelle dedicate, dove spostarli aggiungerebbe solo rischio.
- Rimozione delle colonne in una release successiva: prima un comando che materializza le righe mancanti, poi la migrazione che le elimina.
- Esito: **1758/1758 test verdi** (9 nuovi in `ImpostazioniAssociazioneTest`: fallback sulle colonne, scrittura isolata per associazione, doppia scrittura, prevalenza della riga salvata, upsert senza duplicati, normalizzazione, rifiuto fuori contesto, ripristino del contesto dopo un errore, cascata alla cancellazione, modello parziale), PHPStan pulito, migrazione applicata al DB di sviluppo MySQL, **E2E 109/109**.

## Fase 4 — Archivio dei dati personali (GDPR)

- `spatie/laravel-personal-data-export` 4.3 (aggiunto solo il pacchetto: un primo `composer require -W` aggiornava decine di dipendenze ed è stato annullato). `Socio` e `Account` implementano `ExportsPersonalData`.
- Archivio ZIP: `dati.json` (tutte le sezioni), `riepilogo.pdf` leggibile (dompdf) e `documenti/` con i file caricati del socio. Sezioni del socio: anagrafica con sede, tipo, categoria e nucleo, campi personalizzati, tessere, quote, documenti, eventi, cariche, mansioni, turni di volontariato, prestiti, cessazioni, richieste di modifica dati, storico attività, email ricevute (oggetto, data, esito), account collegati con prenotazioni. Dell'account: profilo, prenotazioni con evento, richieste di iscrizione.
- Mai esportati: password e hash, token, chiavi tecniche (`*_id` di tenant e titolare), percorsi interni, HTML delle email. **Escluse per scelta le note interne della segreteria** (`soci.note`, note sul socio): sono valutazioni dello staff che possono riguardare terzi; se preferisci includerle basta toglierle da `DatiPersonali::ESCLUSI`.
- Socio (profilo dell'area soci) e account (pagina Privacy, accanto all'export JSON immediato già esistente): job in coda, email con link firmato via SMTP dell'associazione, una richiesta ogni 24 ore. Il link è costruito da APP_URL, scade con l'archivio (7 giorni) e il download richiede anche l'accesso come titolare e un nome file che inizia con il suo id. La rotta di download del pacchetto non è usata perché controlla solo la guard di default.
- Segreteria: pulsante "Dati personali" nella scheda socio (permesso `soci.update`, solo nella propria associazione) che genera e scarica subito lo ZIP, per le richieste arrivate via email o di persona.
- Registro attività: richiesta, download ed export della segreteria. Pulizia giornaliera `personal-data-export:clean` alle 03:30; disco privato `storage/app/private/personal-data-exports`.
- Esito: **1763/1763 test verdi** (5 nuovi in `DatiPersonaliTest`: contenuto completo e privo di segreti e note interne, limite di una richiesta al giorno, download solo per il titolare con firma valida e archivio esistente, flusso account, export della segreteria con isolamento tra associazioni), PHPStan pulito.
