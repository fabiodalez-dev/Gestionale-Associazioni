import { expect, test } from '@playwright/test';
import {
    SLUG,
    accountVerificatoLoggato,
    azzeraThrottle,
    creaEvento,
    fixture,
    idPrenotazioneDaEsito,
    loginAdmin,
    nuovaSessione,
    prenota,
    statoPrenotazioni,
} from '../helpers';

test.describe('Preiscrizione eventi (prenotazioni con approvazione)', () => {
    test.beforeEach(() => azzeraThrottle());

    test('evento con approvazione → richiesta "in approvazione", senza QR né biglietto', async ({ page }) => {
        const evento = creaEvento({
            titolo: `Approvazione richiesta ${Date.now()}`,
            capienza: 5,
            'richiede-approvazione': 1,
        });
        const email = await accountVerificatoLoggato(page, 'in-approvazione');

        // La CTA del dettaglio evento esplicita la richiesta di approvazione
        await page.goto(`/a/${SLUG}/eventi/${evento.evento_id}`);
        await expect(page.getByRole('button', { name: /richiedi la prenotazione/i })).toBeVisible();

        await prenota(page, evento.evento_id);

        await expect(page.getByRole('heading', { name: 'Richiesta in attesa di approvazione' })).toBeVisible();
        const idPrenotazione = idPrenotazioneDaEsito(page);

        expect(statoPrenotazioni(email)[0].stato).toBe('in_approvazione');

        // Dettaglio nell'area account: avviso e NESSUN QR finché non approvata
        await page.goto(`/a/${SLUG}/account/prenotazioni/${idPrenotazione}`);
        await expect(page.locator('.alert-info', { hasText: 'in attesa di approvazione' })).toBeVisible();
        await expect(page.locator('.qr-wrapper svg')).toHaveCount(0);
        await expect(page.getByText(/il biglietto con qr sarà disponibile/i)).toBeVisible();
    });

    test('admin approva → prenotazione confermata con QR per l\'account', async ({ page }) => {
        const evento = creaEvento({
            titolo: `Approva admin ${Date.now()}`,
            capienza: 5,
            'richiede-approvazione': 1,
        });
        const email = await accountVerificatoLoggato(page, 'approva-admin');
        await prenota(page, evento.evento_id);
        await expect(page.getByRole('heading', { name: 'Richiesta in attesa di approvazione' })).toBeVisible();
        const idPrenotazione = idPrenotazioneDaEsito(page);

        // Admin: tab prenotazioni con badge "In approvazione" e azione approva
        await nuovaSessione(page);
        await loginAdmin(page);
        await page.goto(`/eventi/${evento.evento_id}/prenotazioni`);

        const riga = page.locator('tr', { hasText: email });
        await expect(riga.locator('.badge', { hasText: 'In approvazione' })).toBeVisible();

        page.once('dialog', (dialog) => dialog.accept());
        await riga.locator('button[title="Approva prenotazione"]').click();
        await expect(page.locator('tr', { hasText: email }).locator('.badge', { hasText: 'Confermata' })).toBeVisible();

        expect(statoPrenotazioni(email)[0].stato).toBe('confermata');

        // L'account ora vede il biglietto con QR
        await nuovaSessione(page);
        await page.goto(`/a/${SLUG}/login`);
        await page.getByLabel(/email/i).fill(email);
        await page.getByLabel(/password/i).fill('AccountE2e2026');
        await page.getByRole('button', { name: /accedi/i }).click();

        await page.goto(`/a/${SLUG}/account/prenotazioni/${idPrenotazione}`);
        await expect(page.locator('.qr-wrapper svg')).toBeVisible();
    });

    test('admin rifiuta con motivo → annullata e il primo in coda viene promosso in approvazione', async ({ page }) => {
        const evento = creaEvento({
            titolo: `Rifiuto e promozione ${Date.now()}`,
            capienza: 1,
            'richiede-approvazione': 1,
        });

        // Account A occupa l'unico posto (in approvazione conta come occupato)
        const emailA = await accountVerificatoLoggato(page, 'rifiuto-a');
        await prenota(page, evento.evento_id);
        await expect(page.getByRole('heading', { name: 'Richiesta in attesa di approvazione' })).toBeVisible();

        // Account B finisce in lista d'attesa
        await nuovaSessione(page);
        const emailB = await accountVerificatoLoggato(page, 'rifiuto-b');
        await prenota(page, evento.evento_id);
        await expect(page.getByText("Sei in lista d'attesa")).toBeVisible();

        // Admin rifiuta A con motivo obbligatorio (modale)
        await nuovaSessione(page);
        await loginAdmin(page);
        await page.goto(`/eventi/${evento.evento_id}/prenotazioni`);

        const rigaA = page.locator('tr', { hasText: emailA });
        await rigaA.locator('button[title^="Rifiuta prenotazione"]').click();
        const modale = page.locator('#modalRifiutaPrenotazione');
        await expect(modale).toBeVisible();
        await modale.locator('#motivo_rifiuto_prenotazione').fill('Evento riservato ai soci E2E');
        await modale.getByRole('button', { name: /rifiuta/i }).click();

        await expect(page.locator('tr', { hasText: emailA }).locator('.badge', { hasText: 'Annullata' })).toBeVisible();

        // A è annullata; B è stato promosso dalla coda e va in approvazione
        expect(statoPrenotazioni(emailA)[0].stato).toBe('annullata');
        expect(statoPrenotazioni(emailB)[0].stato).toBe('in_approvazione');

        // L'account A vede il motivo del rifiuto nel dettaglio
        await nuovaSessione(page);
        await page.goto(`/a/${SLUG}/login`);
        await page.getByLabel(/email/i).fill(emailA);
        await page.getByLabel(/password/i).fill('AccountE2e2026');
        await page.getByRole('button', { name: /accedi/i }).click();

        await page.goto(`/a/${SLUG}/account/prenotazioni`);
        await page.locator('tr', { hasText: evento.titolo }).getByRole('link').first().click();
        const avviso = page.locator('.alert-warning', { hasText: 'La richiesta non è stata approvata' });
        await expect(avviso).toBeVisible();
        await expect(avviso).toContainText('Evento riservato ai soci E2E');
    });

    test('evento a pagamento con approvazione → il pagamento parte solo dopo l\'ok admin', async ({ page }) => {
        fixture('configura-gateway');
        const evento = creaEvento({
            titolo: `Pagamento dopo ok ${Date.now()}`,
            capienza: 5,
            prezzo: 12,
            'richiede-approvazione': 1,
        });
        const email = await accountVerificatoLoggato(page, 'paga-dopo-ok');

        await prenota(page, evento.evento_id);

        // In approvazione: nessun countdown, il pagamento è rimandato all'esito
        await expect(page.getByRole('heading', { name: 'Richiesta in attesa di approvazione' })).toBeVisible();
        const idPrenotazione = idPrenotazioneDaEsito(page);
        await expect(page.getByText(/ti invieremo il link per completare il pagamento/i)).toBeVisible();
        await expect(page.locator('#countdown')).toHaveCount(0);
        expect(statoPrenotazioni(email)[0].stato).toBe('in_approvazione');

        // Admin approva: la prenotazione passa in attesa di pagamento
        await nuovaSessione(page);
        await loginAdmin(page);
        await page.goto(`/eventi/${evento.evento_id}/prenotazioni`);
        page.once('dialog', (dialog) => dialog.accept());
        await page.locator('tr', { hasText: email }).locator('button[title="Approva prenotazione"]').click();
        await expect(page.locator('tr', { hasText: email }).locator('.badge', { hasText: 'In attesa di pagamento' })).toBeVisible();

        expect(statoPrenotazioni(email)[0].stato).toBe('in_attesa_pagamento');

        // L'account ritrova l'esito con countdown e CTA "Paga ora"
        await nuovaSessione(page);
        await page.goto(`/a/${SLUG}/login`);
        await page.getByLabel(/email/i).fill(email);
        await page.getByLabel(/password/i).fill('AccountE2e2026');
        await page.getByRole('button', { name: /accedi/i }).click();

        await page.goto(`/a/${SLUG}/prenotazioni/${idPrenotazione}/esito`);
        await expect(page.getByText('Posti riservati: completa il pagamento')).toBeVisible();
        await expect(page.locator('#countdown')).toBeVisible();
        await expect(page.getByRole('link', { name: /paga ora/i })).toBeVisible();
    });

    test('toggle iscrizioni chiuse → CTA disabilitata con motivo', async ({ page }) => {
        const evento = creaEvento({
            titolo: `Iscrizioni chiuse ${Date.now()}`,
            capienza: 5,
            'iscrizioni-chiuse': 1,
        });
        await accountVerificatoLoggato(page, 'evento-chiuso');

        await page.goto(`/a/${SLUG}/eventi/${evento.evento_id}`);

        await expect(page.getByText('Le iscrizioni a questo evento sono chiuse.')).toBeVisible();
        await expect(page.getByRole('button', { name: /prenota/i })).toBeDisabled();
    });

    test('finestra di iscrizione futura → non prenotabile con data di apertura', async ({ page }) => {
        const evento = creaEvento({
            titolo: `Apertura futura ${Date.now()}`,
            capienza: 5,
            'apertura-futura': 1,
        });
        await accountVerificatoLoggato(page, 'apertura-futura');

        await page.goto(`/a/${SLUG}/eventi/${evento.evento_id}`);

        await expect(page.getByText(/le iscrizioni aprono il \d{2}\/\d{2}\/\d{4}/i)).toBeVisible();
        await expect(page.getByRole('button', { name: /prenota/i })).toBeDisabled();
    });
});
