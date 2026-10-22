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
