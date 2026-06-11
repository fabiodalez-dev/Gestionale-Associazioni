import { expect, test } from '@playwright/test';
import {
    accountVerificatoLoggato,
    azzeraThrottle,
    creaEvento,
    fixture,
    loginAdmin,
    nuovaSessione,
    prenota,
    statoPrenotazioni,
} from '../helpers';

test.describe('Biglietti: verifica pubblica e check-in', () => {
    test.beforeEach(() => azzeraThrottle());

    test('verifica biglietto via URL firmato → "Biglietto valido"', async ({ page }) => {
        const evento = creaEvento({ titolo: `Verifica QR ${Date.now()}`, capienza: 5 });
        const email = await accountVerificatoLoggato(page, 'verifica-qr');
        await prenota(page, evento.evento_id);
        await expect(page.getByText(/prenotazione confermata/i)).toBeVisible();

        const { url, codice } = fixture<{ url: string; codice: string }>('link-biglietto', { email });

        await page.goto(url);
        await expect(page.getByRole('heading', { name: 'Biglietto valido' })).toBeVisible();
        await expect(page.getByText(codice)).toBeVisible();
        await expect(page.getByText(evento.titolo)).toBeVisible();
    });

    test('stesso URL con firma alterata → biglietto non valido', async ({ page }) => {
        const evento = creaEvento({ titolo: `Firma alterata ${Date.now()}`, capienza: 5 });
        const email = await accountVerificatoLoggato(page, 'firma-alterata');
        await prenota(page, evento.evento_id);
        await expect(page.getByText(/prenotazione confermata/i)).toBeVisible();

        const { url } = fixture<{ url: string }>('link-biglietto', { email });
        const manomesso = url.replace(/signature=[0-9a-f]+/, `signature=${'a'.repeat(64)}`);
        expect(manomesso).not.toBe(url);

        await page.goto(manomesso);
        await expect(page.getByRole('heading', { name: 'Biglietto non valido' })).toBeVisible();
        await expect(page.getByText('Link di verifica non valido o manomesso.')).toBeVisible();
    });

    test('check-in admin: primo check-in ok, secondo segnalato come già utilizzato', async ({ page }) => {
        const evento = creaEvento({ titolo: `Check-in ${Date.now()}`, capienza: 5 });
        const email = await accountVerificatoLoggato(page, 'checkin');
        await prenota(page, evento.evento_id);
        await expect(page.getByText(/prenotazione confermata/i)).toBeVisible();
        const codice = statoPrenotazioni(email)[0].codice;

        // Sessione admin (guard web) con token CSRF dal layout
        await nuovaSessione(page);
        await loginAdmin(page);
        const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
        expect(csrf).toBeTruthy();

        const intestazioni = { 'X-CSRF-TOKEN': csrf!, Accept: 'application/json' };

        // Primo check-in: registrato
        const primo = await page.request.post('/biglietti/checkin', {
            headers: intestazioni,
            form: { codice },
        });
        expect(primo.status()).toBe(200);
        const esitoPrimo = await primo.json();
        expect(esitoPrimo.success).toBe(true);
        expect(esitoPrimo.already_checked_in).toBe(false);
        expect(esitoPrimo.biglietto.codice).toBe(codice);

        // Secondo check-in: già utilizzato
        const secondo = await page.request.post('/biglietti/checkin', {
            headers: intestazioni,
            form: { codice },
        });
        expect(secondo.status()).toBe(200);
        const esitoSecondo = await secondo.json();
        expect(esitoSecondo.success).toBe(true);
        expect(esitoSecondo.already_checked_in).toBe(true);
        expect(esitoSecondo.message).toContain('già utilizzato');
    });
});
