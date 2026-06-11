import { expect, test } from '@playwright/test';
import {
    SLUG,
    accountVerificatoLoggato,
    azzeraThrottle,
    creaEvento,
    fixture,
    idPrenotazioneDaEsito,
    nuovaSessione,
    prenota,
    statoPrenotazioni,
} from '../helpers';

test.describe('Prenotazioni dal frontend pubblico', () => {
    test.beforeEach(() => azzeraThrottle());

    test('prenotazione gratuita → confermata con codice PRN', async ({ page }) => {
        const evento = creaEvento({ titolo: `Gratuito conferma ${Date.now()}`, capienza: 5 });
        const email = await accountVerificatoLoggato(page, 'conferma');

        await prenota(page, evento.evento_id);

        await expect(page.getByText(/prenotazione confermata/i)).toBeVisible();
        await expect(page.getByText(/PRN-/)).toBeVisible();

        // Verifica anche lato dati: una sola prenotazione, confermata
        const prenotazioni = statoPrenotazioni(email);
        expect(prenotazioni).toHaveLength(1);
        expect(prenotazioni[0].stato).toBe('confermata');
        expect(prenotazioni[0].codice).toMatch(/^PRN-/);
    });

    test('dettaglio prenotazione: QR SVG renderizzato e biglietto PDF scaricabile', async ({ page }) => {
        const evento = creaEvento({ titolo: `QR e PDF ${Date.now()}`, capienza: 5 });
        await accountVerificatoLoggato(page, 'qrpdf');
        await prenota(page, evento.evento_id);
        await expect(page.getByText(/prenotazione confermata/i)).toBeVisible();
        const idPrenotazione = idPrenotazioneDaEsito(page);

        await page.goto(`/a/${SLUG}/account/prenotazioni/${idPrenotazione}`);

        // QR del biglietto: SVG visibile e con larghezza reale (> 100px)
        const qr = page.locator('.qr-wrapper svg');
        await expect(qr).toBeVisible();
        const box = await qr.boundingBox();
        expect(box).not.toBeNull();
        expect(box!.width).toBeGreaterThan(100);

        // Download del biglietto PDF nella stessa sessione autenticata
        const risposta = await page.request.get(
            `/a/${SLUG}/account/prenotazioni/${idPrenotazione}/biglietto.pdf`,
        );
        expect(risposta.status()).toBe(200);
        expect(risposta.headers()['content-type']).toContain('application/pdf');
    });

    test("evento al completo → la prenotazione finisce in lista d'attesa", async ({ page }) => {
        const evento = creaEvento({
            titolo: `Tutto pieno ${Date.now()}`,
            capienza: 1,
            'posti-prenotati': 1,
        });
        const email = await accountVerificatoLoggato(page, 'lista-attesa');

        await prenota(page, evento.evento_id);

        await expect(page.getByText("Sei in lista d'attesa")).toBeVisible();
        await expect(page.getByText('Posizione in coda:')).toBeVisible();

        const prenotazioni = statoPrenotazioni(email);
        expect(prenotazioni[0].stato).toBe('lista_attesa');
    });

    test("annullando l'occupante la lista d'attesa viene promossa", async ({ page }) => {
        const evento = creaEvento({
            titolo: `Promozione ${Date.now()}`,
            capienza: 1,
            'posti-prenotati': 1,
        });
        const emailB = await accountVerificatoLoggato(page, 'promozione');

        await prenota(page, evento.evento_id);
        await expect(page.getByText("Sei in lista d'attesa")).toBeVisible();

        // L'occupante (fixture) annulla: il posto liberato promuove B (FIFO)
        fixture('annulla-prenotazione', { email: 'occupante1@example.com', evento: evento.evento_id });

        const prenotazioni = statoPrenotazioni(emailB);
        expect(prenotazioni[0].stato).toBe('confermata');

        // Anche la pagina di esito riflette la promozione
        await page.reload();
        await expect(page.getByText(/prenotazione confermata/i)).toBeVisible();
    });

    test('doppia prenotazione sullo stesso evento → errore, resta una sola attiva', async ({ page }) => {
        const evento = creaEvento({ titolo: `Doppia ${Date.now()}`, capienza: 5 });
        const email = await accountVerificatoLoggato(page, 'doppia');

        await prenota(page, evento.evento_id);
        await expect(page.getByText(/prenotazione confermata/i)).toBeVisible();

        // Secondo tentativo: si torna al dettaglio evento con l'errore
        await prenota(page, evento.evento_id);
        await expect(page.getByText('Hai già una prenotazione attiva per questo evento.')).toBeVisible();

        const prenotazioni = statoPrenotazioni(email);
        expect(prenotazioni).toHaveLength(1);
        expect(prenotazioni[0].stato).toBe('confermata');
    });

    test("posti multipli: 2 confermati, gli altri 2 in lista d'attesa (FIFO senza split)", async ({ page }) => {
        const evento = creaEvento({ titolo: `Posti multipli ${Date.now()}`, capienza: 3 });

        // Account A prenota 2 posti su 3 → confermata
        const emailA = await accountVerificatoLoggato(page, 'multipli-a');
        await prenota(page, evento.evento_id, 2);
        await expect(page.getByText(/prenotazione confermata/i)).toBeVisible();

        const prenotazioniA = statoPrenotazioni(emailA);
        expect(prenotazioniA[0].stato).toBe('confermata');
        expect(Number(prenotazioniA[0].posti)).toBe(2);

        // Account B chiede 2 posti ma ne resta 1: lista d'attesa (FIFO stretto)
        await nuovaSessione(page);
        const emailB = await accountVerificatoLoggato(page, 'multipli-b');
        await prenota(page, evento.evento_id, 2);
        await expect(page.getByText("Sei in lista d'attesa")).toBeVisible();

        const prenotazioniB = statoPrenotazioni(emailB);
        expect(prenotazioniB[0].stato).toBe('lista_attesa');
        expect(Number(prenotazioniB[0].posti)).toBe(2);
    });

    test('evento a pagamento → pagina paga con scelta gateway e countdown', async ({ page }) => {
        fixture('configura-gateway');
        const evento = creaEvento({ titolo: `A pagamento ${Date.now()}`, capienza: 5, prezzo: 15 });
        const email = await accountVerificatoLoggato(page, 'pagamento');

        await prenota(page, evento.evento_id);

        // Esito: posti riservati, countdown e invito a pagare
        await expect(page.getByText('Posti riservati: completa il pagamento')).toBeVisible();
        await expect(page.locator('#countdown')).toBeVisible();

        await page.getByRole('link', { name: /paga ora/i }).click();

        // Pagina di pagamento: importo, countdown e scelta del gateway
        await expect(page.getByText('€ 15,00')).toBeVisible();
        await expect(page.locator('#countdown')).toBeVisible();
        await expect(page.getByRole('button', { name: /paga con carta \(stripe\)/i })).toBeVisible();

        // Stato dati: in attesa di pagamento (nessun pagamento reale nei test)
        const prenotazioni = statoPrenotazioni(email);
        expect(prenotazioni[0].stato).toBe('in_attesa_pagamento');
    });
});
