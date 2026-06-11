import { expect, test } from '@playwright/test';
import {
    azzeraThrottle,
    creaEvento,
    creaSocioConTessera,
    emailUnica,
    fixture,
    loginAdmin,
    loginSocio,
    postJsonConCsrf,
    SOCIO_PASSWORD,
} from '../helpers';

test.describe('Tessere e QR', () => {
    test.beforeEach(() => azzeraThrottle());

    test('verifica pubblica: tessera valida con dati del socio', async ({ page }) => {
        const socio = creaSocioConTessera(emailUnica('tessera-valida'));

        await page.goto(`/verifica-tessera/${socio.tessera_id}`);

        await expect(page.getByRole('heading', { name: 'Tessera Valida' })).toBeVisible();
        await expect(page.getByText('Associazione E2E')).toBeVisible();
        await expect(page.getByText(`N. Tessera: ${socio.numero_tessera}`)).toBeVisible();
        await expect(page.getByText('Socio: Socio E2E')).toBeVisible();
    });

    test('verifica pubblica: tessera scaduta segnalata come non attiva', async ({ page }) => {
        const socio = creaSocioConTessera(emailUnica('tessera-scaduta'), { scaduta: 1 });

        await page.goto(`/verifica-tessera/${socio.tessera_id}`);

        await expect(page.getByRole('heading', { name: 'Tessera Scaduta / Non Attiva' })).toBeVisible();
        await expect(page.getByText(`N. Tessera: ${socio.numero_tessera}`)).toBeVisible();
    });

    test('verifica pubblica: UUID inesistente → tessera non trovata; senza parametro → invito alla scansione', async ({ page }) => {
        await page.goto('/verifica-tessera/00000000-0000-7000-8000-000000000000');
        await expect(page.getByRole('heading', { name: 'Tessera Non Trovata' })).toBeVisible();

        await page.goto('/verifica-tessera');
        await expect(page.getByRole('heading', { name: 'Verifica Tessera Associativa' })).toBeVisible();
        await expect(page.getByRole('link', { name: /scansiona con fotocamera/i })).toBeVisible();
    });

    test('PDF tessera dall\'admin: 200 application/pdf', async ({ page }) => {
        const socio = creaSocioConTessera(emailUnica('tessera-pdf'));

        await loginAdmin(page);
        const risposta = await page.request.get(`/tessere/${socio.tessera_id}/pdf`);

        expect(risposta.status()).toBe(200);
        expect(risposta.headers()['content-type']).toContain('application/pdf');
    });

    test('check-in tessera dallo scanner admin: prima volta ok, seconda volta già registrato', async ({ page }) => {
        const evento = creaEvento({ titolo: `Checkin tessera ${Date.now()}`, capienza: 20 });
        const socio = creaSocioConTessera(emailUnica('tessera-checkin'));

        await loginAdmin(page);
        // Una pagina visitata garantisce il cookie XSRF-TOKEN in sessione
        await page.goto('/tessere/checkin');

        const primo = await postJsonConCsrf(page, '/tessere/checkin', {
            evento_id: evento.evento_id,
            tessera_id: socio.tessera_id,
        });
        expect(primo.status).toBe(200);
        expect(primo.body.success).toBe(true);
        expect(primo.body.already_checked_in).toBe(false);
        expect(primo.body.message).toContain('Check-in effettuato con successo');

        const secondo = await postJsonConCsrf(page, '/tessere/checkin', {
            evento_id: evento.evento_id,
            tessera_id: socio.tessera_id,
        });
        expect(secondo.body.success).toBe(true);
        expect(secondo.body.already_checked_in).toBe(true);
    });

    test('le pagine scanner si caricano (check-in admin e scanner pubblico)', async ({ page }) => {
        creaEvento({ titolo: `Scanner pagina ${Date.now()}`, capienza: 5 });

        await loginAdmin(page);
        await page.goto('/tessere/checkin');
        await expect(page.getByRole('heading', { name: /seleziona evento/i })).toBeVisible();
        await expect(page.locator('.event-card').first()).toBeVisible();

        // Scanner tessera standalone: pubblico, con input manuale di fallback
        await page.goto('/scanner-tessera');
        await expect(page.getByRole('heading', { name: 'Scanner Tessera' })).toBeVisible();
        await expect(page.locator('#manualInput')).toBeVisible();
    });

    test('area soci: login del socio dopo set-password via fixture', async ({ page }) => {
        const email = emailUnica('socio-login');
        creaSocioConTessera(email, { 'senza-tessera': 1 });
        fixture('set-password-socio', { email, password: SOCIO_PASSWORD });

        await loginSocio(page, email);

        await expect(page.getByRole('heading', { name: 'Ciao, Socio!' })).toBeVisible();
        await expect(page.getByText('Nessuna tessera attiva')).toBeVisible();
    });

    test('area soci: tessera con QR visibile in dashboard e quota in elenco', async ({ page }) => {
        const email = emailUnica('socio-tessera-qr');
        const socio = creaSocioConTessera(email, { password: SOCIO_PASSWORD, 'con-quota': 1 });

        await loginSocio(page, email);

        // Dashboard: card tessera con numero e immagine QR renderizzata
        await expect(page.getByText(`N. ${socio.numero_tessera}`)).toBeVisible();
        const qr = page.locator(`img[alt="QR code tessera ${socio.numero_tessera}"]`);
        await expect(qr).toBeVisible();
        const box = await qr.boundingBox();
        expect(box).not.toBeNull();
        expect(box!.width).toBeGreaterThan(80);

        // Elenco tessere con download PDF nella stessa sessione socio
        await page.goto('/area-soci/tessere');
        const riga = page.locator('tr', { hasText: socio.numero_tessera! });
        await expect(riga).toBeVisible();
        await expect(riga.locator('.badge', { hasText: 'Attiva' })).toBeVisible();

        const pdf = await page.request.get(`/area-soci/tessere/${socio.tessera_id}/pdf`);
        expect(pdf.status()).toBe(200);
        expect(pdf.headers()['content-type']).toContain('application/pdf');

        // Quote: la quota da pagare è in elenco
        await page.goto('/area-soci/quote');
        await expect(page.getByRole('heading', { name: 'Le Mie Quote' })).toBeVisible();
        const rigaQuota = page.locator('tr', { hasText: 'Quota Annuale' });
        await expect(rigaQuota).toBeVisible();
        await expect(rigaQuota.getByText('25,00')).toBeVisible();
    });
});
