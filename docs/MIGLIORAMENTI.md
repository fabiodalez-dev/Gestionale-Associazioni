# I 25 Miglioramenti rispetto all'app attuale

## Sicurezza (1–8)
1. **2FA TOTP per gli admin** (Fortify) — prima inesistente.
2. **Audit trail completo** (activitylog): ogni create/update/delete tracciato con utente, IP e diff dei campi.
3. **Ruoli e permessi granulari** (spatie/permission): permessi per singola funzione, non più 3 ruoli hardcoded nelle pagine.
4. **Rate limiting** su login admin/soci, API e form pubblici (throttle nativo) — prima nessuna protezione brute-force.
5. **Content-Security-Policy** (spatie/csp) + security headers — prima nessun header di sicurezza.
6. **Honeypot anti-spam** sulla preiscrizione pubblica (spatie/honeypot) — prima esposta ai bot.
7. **Sanitizzazione HTML centralizzata** (mews/purifier) per template email GrapesJS e privacy policy — prima HTML salvato grezzo.
8. **Isolamento multi-tenant a livello framework**: global scope su `associazione_id` — prima affidato a WHERE manuali in ogni query (una dimenticanza = data leak).

## Gestione dati (9–16)
9. **Validazione codice fiscale italiano** con checksum reale (robertogallea/laravel-codicefiscale) — prima solo lunghezza.
10. **Validazione telefoni internazionale** (propaganistas/laravel-phone).
11. **Soft delete + cestino** per soci, documenti e verbali — prima cancellazione definitiva immediata.
12. **Import soci da Excel/CSV** con validazione riga per riga e report errori (maatwebsite/excel) — prima solo export.
13. **Export Excel nativo** (.xlsx con formattazione) oltre al CSV.
14. **Ricerca full-text** sui soci (Laravel Scout) — prima solo LIKE su pochi campi.
15. **DTO tipizzati** (spatie/laravel-data) per request/response — prima array associativi non validati.
16. **Documenti con MediaLibrary**: conversioni, thumbnail, download firmati con URL temporanei — prima file in `uploads/` accessibili.

## Affidabilità e operazioni (17–21)
17. **Backup automatici pianificati** di DB + file con notifica email e rotazione (spatie/backup) — prima backup manuale via endpoint.
18. **Coda email robusta**: queue Laravel con retry, backoff esponenziale e `failed_jobs` — prima coda custom senza retry affidabile.
19. **Scheduler integrato e monitorato** (schedule-monitor): alert se un job pianificato non gira — prima cron esterni senza monitoraggio.
20. **Health check** `/health` (DB, coda, scheduler, spazio disco, debug mode) per uptime monitoring.
21. **Test automatici** (feature + unit) + pint + larastan livello 5 — prima zero test.

## API e CRM (22–25)
22. **API documentate automaticamente** OpenAPI/Swagger (dedoc/scramble) con UI interattiva — prima docs manuali statiche.
23. **Token API Sanctum** con abilities/scope e revoca per singolo token, in affiancamento alle api_keys legacy.
24. **Filtri/sort/paginazione standard** su tutte le API (spatie/query-builder): `?filter[stato]=Attivo&sort=-created_at`.
25. **Notifiche unificate** (database + email) con sistema notifiche Laravel: timeline attività socio, promemoria scadenze, esiti pagamento — con preferenze e opt-out per socio.
