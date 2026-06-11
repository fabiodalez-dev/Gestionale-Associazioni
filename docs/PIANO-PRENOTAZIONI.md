# Sistema di prenotazione eventi con frontend pubblico, account utenti e area riservata

## Contesto

L'app Laravel (`/Users/fabio/Documents/GitHub/gestione-associazioni-laravel`) gestisce oggi gli eventi solo lato admin (CRUD + partecipanti soci + check-in tessera). Si aggiunge un **sistema di prenotazione pubblico**: ogni associazione ha un mini-sito eventi brandizzato, i visitatori si registrano come **account ospite** (entità separata dai soci, promuovibile a socio), prenotano posti — anche a pagamento via Stripe/PayPal — ricevono un **biglietto con QR**, e gestiscono tutto da un'**area riservata ricca** (prenotazioni, storico, profilo, GDPR, richiesta "diventa socio", tessera/quote se collegati a un socio).

Decisioni di prodotto (confermate dall'utente): account separato dai soci · frontend per-associazione `/a/{slug}` · eventi anche a pagamento · capienza con lista d'attesa e promozione automatica.

L'implementazione sarà eseguita da un **Workflow multi-agente** (richiesto dall'utente) dopo l'approvazione di questo piano.

## Architettura (riusa l'esistente)

| Esigenza | Riuso |
|---|---|
| Pagamenti | `app/Services/Pagamenti/PaymentService.php` + gateway: nuova interfaccia `Pagabile` (Quota e Prenotazione), dispatch webhook via `metadata.tipo` (default `quota` per retrocompatibilità) |
| Tenant senza sessione admin | `app/Models/Concerns/BelongsToAssociazione.php`: `currentAssociazioneId()` legge prima il binding `app('tenant.pubblico')` impostato dal nuovo middleware `RisolviTenantPubblico` |
| Auth account | Pattern guard `socio` (`config/auth.php`, `AutenticaSocio`, `routes/modules/02-area-soci.php`) → guard `account` + broker password + verifica email obbligatoria |
| QR/PDF | `TesseraQrService::generateSvg()` (già generico) + dompdf; scanner `html5-qrcode` esistente esteso ai biglietti |
| Email per tenant | `EmailService`/`TenantMailer` + Notifiche Laravel database+mail (pattern `PagamentoQuotaNotification`) |
| Scheduler | `routes/console.php` |

## Schema dati

**`associazioni`** +: `slug` string(100) unique (backfill da nome), `frontend_pubblico_attivo` bool default false, `descrizione_pubblica` text null.

**`eventi`** +: `stato_pubblicazione` ('bozza'|'pubblicato'|'archiviato', default bozza), `capienza` uint null (null=illimitata), `prezzo` decimal(10,2) default 0, `iscrizioni_dal/al` datetime null, `max_posti_per_prenotazione` utiny default 4, `lista_attesa_attiva` bool default true, `annullamento_entro_ore` usmall default 24, `immagine_url` text null, `data_fine` datetime null. Indice `(associazione_id, stato_pubblicazione, data_evento)`. **Nessun contatore denormalizzato**.

**`accounts`** (nuova): id uuid, associazione_id FK, nome, cognome, email — **unique(associazione_id, email)** —, password, email_verified_at, telefono null, `socio_id` uuid **unique** null FK soci nullOnDelete, attivo bool, preferenze json null, privacy_accettata_at, richiesta_socio_at null, ultimo_accesso_at null, remember_token, timestamps, softDeletes. Trait: HasUuids, BelongsToAssociazione, Authenticatable, MustVerifyEmail, Notifiable.

**`prenotazioni`** (nuova): id uuid, associazione_id FK, evento_id FK cascade, account_id FK **restrictOnDelete**, `codice` string(20) unique per tenant (es. PRN-7K3M2A, base32 non ambiguo), `qr_token` char(64) unique globale, `stato` string(20), `posti` utiny default 1, `prezzo_unitario` + `importo_totale` decimal (snapshot), gateway/gateway_transaction_id/pagata_at, `scadenza_pagamento_at`, `promossa_at`, `annullata_at` + motivo, `checkin_at` + checkin_utente_id FK utenti, note, timestamps. Indici: `(evento_id, stato)`, `(account_id, created_at)`, `(stato, scadenza_pagamento_at)`.

## Macchina a stati prenotazione

Stati: `in_attesa_pagamento` · `confermata` · `lista_attesa` · `annullata` · `scaduta`. Check-in = `checkin_at` su una confermata (non uno stato).

- Creazione → `confermata` (gratuito, posti ok) | `in_attesa_pagamento` (a pagamento, posti **riservati**, scadenza now+30min) | `lista_attesa` (pieno).
- `in_attesa_pagamento` → `confermata` (webhook) | `scaduta` (scheduler ogni minuto) | `annullata` (utente). Scadute/annullate → rilascio posti → promozione.
- `confermata` → `annullata`: utente entro `data_evento − annullamento_entro_ore` e senza check-in; admin sempre. Rimborsi manuali (v1).
- Promozione **FIFO stretto** (si ferma al primo che non entra): gratuito → confermata; a pagamento → in_attesa_pagamento con finestra **24h** dedicata.

**Concorrenza (regola unica)**: ogni operazione che consuma/rilascia posti gira in `DB::transaction` acquisendo prima `lockForUpdate()` sulla riga `eventi` (mutex per-evento); il conteggio è sempre `SUM(posti) WHERE stato IN (confermata, in_attesa_pagamento)` dentro il lock. Idempotenza webhook come per le quote (ack se già pagata). Pagamento su prenotazione già scaduta → riconferma anche in overbooking (visibile in admin).

## Rotte

**Pubbliche** `routes/modules/03-frontend-pubblico.php`, prefix `a/{associazione:slug}`, middleware `RisolviTenantPubblico` (404 se slug inesistente o frontend disattivo; logout guard account se cross-tenant):
home, eventi.index/show (solo pubblicati), registrati (throttle 5/m + honeypot), verifica-email (signed), login (throttle), password reset (broker accounts), logout.
**Autenticate** (guard account + verificato): `POST eventi/{evento}/prenota`, `prenotazioni/{p}/paga` (scelta gateway, countdown), `checkout/{gateway}`, `esito`.
**Globale**: `GET /verifica-biglietto/{token}` (signed + throttle, token [a-f0-9]{64}).

**Area riservata** `/a/{slug}/account/*`: dashboard · prenotazioni (filtri stato, posizione in coda) · dettaglio con QR inline · biglietto.pdf · annulla · storico con esito check-in · profilo (dati, cambio password con corrente, cambio email con ri-verifica) · preferenze (newsletter/promemoria/marketing) · privacy (export JSON, cancellazione con conferma password → anonimizzazione) · diventa-socio (richiesta → notifica admin) · tessera+quote (**solo se socio_id**, riusa payment_token quote esistente).

**Admin**: form evento esteso (sezione Pubblicazione: tutti i nuovi campi; capienza non riducibile sotto i confermati) · `eventi/{evento}/prenotazioni` (lista, conferma manuale offline, annulla, promuovi, reinvia biglietto, export CSV) · scanner esistente esteso (auto-riconoscimento payload tessera vs `/verifica-biglietto/`; già usato = warning giallo con orario, non errore) · `accounts` (lista, dettaglio, collega-socio con match suggerito per email ma azione sempre esplicita, promuovi-socio che crea Socio da anagrafica, disattiva, richieste socio pendenti) · KPI dashboard (prenotazioni 30gg, riempimento, incasso eventi, coda, account).

## Notifiche (ShouldQueue, database+mail per tenant)

`App\Notifications\Accounts\`: VerificaEmailAccount, BenvenutoAccount. `App\Notifications\Prenotazioni\`: Confermata (PDF allegato), InAttesaPagamento (link+30'), InListaAttesa (posizione), PromozioneListaAttesa (finestra 24h), PagamentoScaduto, Annullata, PromemoriaEvento (24h prima, se preferenza). Per admin: RichiestaSocioRicevuta, EventoEsaurito.
Scheduler: `prenotazioni:gestisci-scadenze` (everyMinute, withoutOverlapping), `prenotazioni:promemoria-eventi` (daily).

## Edge case presidiati

Oversell (mutex per-evento) · webhook replay (idempotenza) · doppia prenotazione attiva stesso account+evento (check in transazione) · collegamento socio mai automatico per email (anti account-takeover, socio_id unique) · GDPR delete = anonimizzazione (prenotazioni/contabilità intatte, FK restrict) · 404 uniforme su slug (anti-enumerazione) · sessioni checkout legacy in volo (metadata default tipo=quota).

## Esecuzione: Workflow multi-agente

Fasi (ognuna con test propri, convenzioni del progetto: Form Request, Policy, viste sul design system, rotte in routes/modules/):
1. **Fondamenta** (1 agente): 4 migrations, modelli (Account, Prenotazione, estensioni Evento/Associazione), guard account, RisolviTenantPubblico + estensione BelongsToAssociazione, factories.
2. **Moduli in parallelo** (dopo la fase 1):
   a. Auth pubblica + frontend catalogo (registrazione/verifica/login/reset, home/eventi brandizzati)
   b. PrenotazioneService + stati/FIFO/lock + scheduler scadenze + comandi
   c. Pagabile + refactor gateway + dispatch webhook + checkout prenotazione
   d. QR/PDF biglietto + verifica + estensione scanner
3. **Area riservata** (1 agente, dopo 2a/2b): tutte le pagine account incl. GDPR e diventa-socio.
4. **Admin** (1 agente, parallelo alla 3): form pubblicazione, tab prenotazioni, accounts, KPI.
5. **Notifiche + rifiniture** (1 agente): notifiche, promemoria, seeder demo aggiornato, REPORT.
6. **Verifica** (loop): suite completa + phpstan + pint fino a verde; verifica visiva con Playwright (frontend pubblico, prenotazione end-to-end gratuita, area riservata, scanner) e fix.

## Verifica finale

- `php artisan test` (suite completa, attesi ~700+ test) · `vendor/bin/phpstan` livello 5 · `vendor/bin/pint --test`
- Flusso reale nel browser (server su :8010): attivare frontend per l'associazione "Test", pubblicare un evento con capienza 2 e uno a pagamento, registrare un account, verificare email (log mailer), prenotare → QR in area riservata → check-in da scanner admin → terzo account in lista d'attesa → annullo → promozione automatica.
- Commit incrementali per fase, nessuna menzione AI.
