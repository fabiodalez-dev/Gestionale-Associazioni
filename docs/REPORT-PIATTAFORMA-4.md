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
