# Report — Sistema di prenotazione eventi con frontend pubblico

Implementazione completa del piano `docs/PIANO-PRENOTAZIONI.md` (workflow multi-agente: fondamenta → moduli paralleli → area riservata + admin → notifiche e rifiniture).

## Cosa è stato costruito

### Fondamenta (schema dati e tenant pubblico)
- **Migrations**: `notifications` (canale database), `associazioni` + `slug`/`frontend_pubblico_attivo`/`descrizione_pubblica`, `eventi` + campi pubblicazione (stato, capienza, prezzo, finestre iscrizioni, max posti, lista d'attesa, annullamento, immagine, data fine), tabelle `accounts` e `prenotazioni`. Tutte UUID + `associazione_id`, compatibili SQLite (stati come string, niente enum nativi).
- **Modelli**: `Account` (guard `account`, MustVerifyEmail, soft delete + anonimizzazione GDPR), `Prenotazione` (macchina a stati, codice `PRN-XXXXXX` base32 non ambiguo, `qr_token` 64 hex unique, implementa `Pagabile`), estensioni `Evento`/`Associazione`.
- **Tenant senza sessione admin**: middleware `RisolviTenantPubblico` (404 uniforme su slug inesistente o frontend disattivo, logout cross-tenant) che imposta il binding `app('tenant.pubblico')` letto da `BelongsToAssociazione`.

### Frontend pubblico (`/a/{slug}`)
- Home brandizzata + catalogo eventi (solo `pubblicato`), layout pubblico dedicato sul design system.
- Auth account: registrazione (honeypot + throttle 5/m), verifica email firmata (TTL 60'), login/logout tenant-scoped, password reset con broker `accounts`.

### Macchina a stati prenotazioni (`PrenotazioneService`)
- Stati: `in_attesa_pagamento` · `confermata` · `lista_attesa` · `annullata` · `scaduta`; check-in = `checkin_at` su una confermata.
- Concorrenza: ogni operazione sui posti in `DB::transaction` con `lockForUpdate()` sulla riga `eventi` (mutex per-evento); conteggio sempre `SUM(posti)` dentro il lock, nessun contatore denormalizzato.
- Promozione FIFO **stretto**: gratuito → confermata, a pagamento → in attesa con finestra dedicata di 24h.
- Idempotenza webhook (replay ack-ato) e riconferma post-scadenza anche in overbooking.
- Scheduler (`routes/console.php`): `prenotazioni:gestisci-scadenze` (everyMinute, withoutOverlapping) e `prenotazioni:promemoria-eventi` (dailyAt 08:30).

### Pagamenti
- Interfaccia `Pagabile` (Quota e Prenotazione), refactor gateway Stripe/PayPal con dispatch webhook via `metadata.tipo` (default `quota` per le sessioni legacy in volo).
- Pagina `paga` con scelta gateway e countdown; esito su `prenotazioni.esito` (lo stato live fa fede).

### Biglietto QR
- `BigliettoService`: URL firmato `/verifica-biglietto/{token}`, QR SVG/PNG, PDF dompdf brandizzato.
- Scanner admin esteso: auto-riconoscimento payload tessera vs biglietto; biglietto già usato = warning con orario (non errore).

### Area riservata account (`/a/{slug}/account/*`)
Dashboard · prenotazioni con filtri e posizione in coda · dettaglio con QR inline · biglietto PDF · annullo · storico con esito check-in · profilo (dati, cambio password/email con ri-verifica) · preferenze · privacy GDPR (export JSON, cancellazione = anonimizzazione) · diventa-socio · tessera+quote (solo se collegato a un socio).

### Admin
Form evento con sezione Pubblicazione (capienza non riducibile sotto i confermati) · tab prenotazioni per evento (conferma offline, annulla, promuovi, reinvia biglietto, export CSV) · gestione `accounts` (collega-socio sempre esplicito, promuovi-socio, disattiva/riattiva, richieste pendenti) · KPI dashboard.

### Notifiche (fase Rifiniture — questo modulo)
Tutte `ShouldQueue`, canali **database + mail**, email instradata sull'SMTP del tenant quando configurato (`TenantNotificationMailer`) con fallback al mailer di default. Invio **sempre fuori dalle transazioni** di dominio, best-effort con log su errore.

| Notifica | Destinatario | Trigger |
|---|---|---|
| `Accounts\VerificaEmailAccount` | account | registrazione / cambio email / reinvio |
| `Accounts\BenvenutoAccount` | account | prima verifica email riuscita |
| `Accounts\ResetPasswordAccount` | account | richiesta reset (broker `accounts`) |
| `Prenotazioni\ConfermataNotification` (PDF biglietto allegato, best-effort) | account | creazione gratuita con posti / webhook pagamento / conferma offline |
| `Prenotazioni\InAttesaPagamentoNotification` (link pagamento + scadenza 30') | account | creazione a pagamento |
| `Prenotazioni\InListaAttesaNotification` (posizione in coda) | account | creazione su evento pieno |
| `Prenotazioni\PromozioneListaAttesaNotification` (finestra 24h se a pagamento) | account | promozione FIFO (annullo/scadenza/azione admin) |
| `Prenotazioni\PagamentoScadutoNotification` | account | scheduler `gestisci-scadenze` |
| `Prenotazioni\AnnullataNotification` (con motivo) | account | annullo utente o admin |
| `Prenotazioni\PromemoriaEventoNotification` | account | scheduler `promemoria-eventi` (evento tra 24h e 48h, preferenza `promemoria_eventi` attiva, default attiva) |
| `Prenotazioni\BigliettoPrenotazioneNotification` | account | azione admin "reinvia biglietto" |
| `Prenotazioni\EventoEsauritoNotification` | admin del tenant (`admin_associazione` attivi) | la prenotazione che consuma gli ultimi posti |
| `Accounts\RichiestaSocioRicevuta` | admin del tenant | richiesta "diventa socio" |

Gli account disattivati/anonimizzati non ricevono notifiche (guard in `PrenotazioneService::notificaAccount`).

## Rotte principali

```
# Frontend pubblico (middleware RisolviTenantPubblico, prefix a/{associazione:slug})
GET    /a/{slug}                                   pubblico.home.show
GET    /a/{slug}/eventi[/{evento}]                 pubblico.eventi.index|show
GET|POST /a/{slug}/registrati                      pubblico.registrazione[.store]   (honeypot + throttle)
GET    /a/{slug}/verifica-email/{id}/{hash}        pubblico.verifica-email.verifica (signed + throttle)
GET|POST /a/{slug}/login                           pubblico.login[.attempt]
GET|POST /a/{slug}/password/...                    pubblico.password.*              (broker accounts)

# Autenticate (guard account + valido + verificato)
POST   /a/{slug}/eventi/{evento}/prenota           pubblico.eventi.prenota
GET    /a/{slug}/prenotazioni/{p}/paga             pubblico.prenotazioni.paga
POST   /a/{slug}/prenotazioni/{p}/checkout/{gw}    pubblico.prenotazioni.checkout   (stripe|paypal)
GET    /a/{slug}/prenotazioni/{p}/esito            pubblico.prenotazioni.esito

# Area riservata
GET    /a/{slug}/account                           pubblico.account.dashboard
GET    /a/{slug}/account/prenotazioni[/{p}]        pubblico.account.prenotazioni.index|show
GET    /a/{slug}/account/prenotazioni/{p}/biglietto.pdf  pubblico.account.prenotazioni.biglietto
POST   /a/{slug}/account/prenotazioni/{p}/annulla  pubblico.account.prenotazioni.annulla
GET    /a/{slug}/account/storico|profilo|preferenze|privacy|tessera   pubblico.account.*
GET    /a/{slug}/account/privacy/export            pubblico.account.privacy.export
POST   /a/{slug}/account/privacy/cancella          pubblico.account.privacy.cancella
GET|POST /a/{slug}/account/diventa-socio           pubblico.account.diventa-socio[.store]

# Globale
GET    /verifica-biglietto/{token}                 biglietti.verifica               (signed + throttle)

# Admin (auth utenti)
GET    /eventi/{evento}/prenotazioni               eventi.prenotazioni.index (+export CSV)
POST   /eventi/{evento}/prenotazioni/promuovi      eventi.prenotazioni.promuovi
POST   /eventi/{evento}/prenotazioni/{p}/annulla|conferma-pagamento|reinvia-biglietto
GET    /accounts[/{account}]                       accounts.index|show
POST   /accounts/{account}/collega-socio|promuovi-socio
PATCH  /accounts/{account}/disattiva|riattiva
```

(Elenco completo: `php artisan route:list --path=a/` e `--name=prenotazioni` / `--name=accounts`.)

## Come provarlo end-to-end in locale

1. **Setup**: `php artisan migrate:fresh && php artisan db:seed --class=DemoSeeder` (oppure usa l'installer web). Il seeder demo crea l'associazione `associazione-demo` con frontend pubblico attivo, un evento gratuito pubblicato (capienza 50), uno a pagamento (€ 25, capienza 30) e l'account demo **demo@account-demo.test / demo1234** (verificato, con una prenotazione confermata da 2 posti).
2. **Server**: `php artisan serve --port=8010`; mailer consigliato in dev: `MAIL_MAILER=log` (le email finiscono in `storage/logs/laravel.log`). Con `QUEUE_CONNECTION=database` avvia anche `php artisan queue:work`; con `sync` non serve.
3. **Frontend**: apri `http://localhost:8010/a/associazione-demo` → catalogo eventi → login con l'account demo (o registrane uno nuovo e verifica l'email dal log: arriva anche il benvenuto).
4. **Prenotazione gratuita**: prenota l'Assemblea → conferma immediata, email con biglietto PDF allegato, QR nel dettaglio dell'area riservata.
5. **Prenotazione a pagamento**: prenota la Cena → stato "in attesa di pagamento" con countdown 30'; configura i gateway del tenant (Stripe/PayPal sandbox) per il checkout, oppure lascia scadere e lancia `php artisan prenotazioni:gestisci-scadenze` per vedere scadenza + email.
6. **Lista d'attesa**: da admin riduci la capienza (non sotto i confermati) o riempi l'evento con più account → terzo account in coda con posizione → annulla una confermata → promozione FIFO automatica + email (gli admin ricevono "evento al completo" quando si esauriscono i posti).
7. **Check-in**: da admin apri lo scanner e inquadra il QR del biglietto (o visita l'URL firmato del QR) → check-in registrato; secondo passaggio = warning "già usato" con orario.
8. **Promemoria**: con un evento tra 24 e 48 ore, `php artisan prenotazioni:promemoria-eventi`.
9. **Scheduler reale**: `php artisan schedule:work`.

## Test

- `tests/Feature/Prenotazioni/` — 187 test, 603 assertion, tutti verdi (auth pubblica, catalogo, service/macchina a stati, HTTP prenota, checkout/webhook, biglietti/check-in, area riservata, GDPR, notifiche).
- Notifiche: `NotifichePrenotazioniTest` (Notification::fake) copre conferma, attesa pagamento, lista d'attesa con posizione, evento esaurito ai soli admin attivi del tenant, annullo + promozione, scadenza pagamenti, opt-out promemoria, account disattivato, benvenuto post-verifica (una sola volta).
- La separazione invio/transazione è verificata da `PrenotazioneServiceTest` tramite la spia (`DB::transactionLevel() === 0` al momento dell'invio).

## Limitazioni note (v1)

- **Rimborsi manuali**: l'annullo di una prenotazione pagata non rimborsa via gateway; l'email all'utente rimanda al contatto con l'associazione e il follow-up è in admin.
- **Overbooking accettato** sul pagamento arrivato dopo la scadenza: la prenotazione viene riconfermata anche oltre capienza (denaro già incassato), l'eccedenza è visibile in admin.
- **Promozione FIFO stretto**: la coda si ferma al primo che non entra nei posti rimasti, anche se uno successivo più piccolo entrerebbe (scelta di equità del piano).
- **EventoEsaurito** scatta quando una prenotazione consuma gli ultimi posti alla creazione; se l'esaurimento avviene per riduzione di capienza da admin non viene rinotificato.
- **Un promemoria per prenotazione**: garanzia data dalla finestra 24–48h + run giornaliero; se lo scheduler salta un giorno il promemoria può andare perso.
- Il PDF del biglietto in allegato è best-effort: se la generazione fallisce l'email di conferma parte comunque (il biglietto resta scaricabile dall'area riservata).
- Le notifiche `ShouldQueue` richiedono un queue worker attivo in produzione (supervisor, vedi PIANO-MIGRAZIONE.md); con `QUEUE_CONNECTION=sync` l'invio è inline.
