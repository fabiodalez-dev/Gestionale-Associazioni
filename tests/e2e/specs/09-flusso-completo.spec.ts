import { expect, test } from '@playwright/test';
import {
    SLUG,
    accountVerificatoLoggato,
    azzeraThrottle,
    configuraFormIscrizione,
    creaEvento,
    inviaIscrizione,
    loginAccount,
    loginAdmin,
    nuovaSessione,
    postJsonConCsrf,
    prenota,
    statoPrenotazioni,
    statoRichiesta,
} from '../helpers';

test.describe('Regressione: il cerchio completo del tesseramento', () => {
    test.beforeEach(() => {
        azzeraThrottle();
        configuraFormIscrizione(); // default: aperte, preiscrizione
    });

    test('catalogo → iscrizione socio → approvazione con tessera → prenotazione → check-in', async ({ page }) => {
        const evento = creaEvento({ titolo: `Cerchio completo ${Date.now()}`, capienza: 10 });

        // 1. L'utente parte dal catalogo pubblico e arriva al form di iscrizione
        const email = await accountVerificatoLoggato(page, 'cerchio');
        await page.goto(`/a/${SLUG}/eventi`);
        await expect(page.locator('.evento-card', { hasText: evento.titolo })).toBeVisible();

        await inviaIscrizione(page, { telefono: '+39 333 1112233', data_nascita: '1992-04-18' });
        await expect(page.getByText('Richiesta di iscrizione inviata!')).toBeVisible();

        // 2. L'admin approva la richiesta generando subito la tessera
        await nuovaSessione(page);
        await loginAdmin(page);
        await page.goto('/richieste-iscrizione');
        const rigaRichiesta = page.locator('tr', { hasText: email });
        await rigaRichiesta.getByRole('button', { name: /approva/i }).click();
        const modale = page.locator('#modalApprova');
        await expect(modale).toBeVisible();
        await modale.locator('#genera_tessera').check();
        await modale.getByRole('button', { name: /approva/i }).click();
        await expect(page.getByText(/richiesta approvata/i).first()).toBeVisible();

        const richiesta = statoRichiesta(email);
        expect(richiesta.socio_stato).toBe('Attivo');
        expect(richiesta.account_socio_id).not.toBeNull();

        // 3. Da socio, l'account vede la tessera e prenota l'evento
        await nuovaSessione(page);
        await loginAccount(page, email);
        await page.goto(`/a/${SLUG}/account/tessera`);
        await expect(page.getByText(/tessera n\./i)).toBeVisible();

        await prenota(page, evento.evento_id);
        await expect(page.getByText(/prenotazione confermata/i)).toBeVisible();
        const codice = statoPrenotazioni(email)[0].codice;
        expect(codice).toMatch(/^PRN-/);

        // 4. All'ingresso l'admin fa il check-in del biglietto dallo scanner
        await nuovaSessione(page);
        await loginAdmin(page);
        await page.goto('/tessere/checkin');

        const checkin = await postJsonConCsrf(page, '/biglietti/checkin', { codice });
        expect(checkin.status).toBe(200);
        expect(checkin.body.success).toBe(true);
        expect(checkin.body.already_checked_in).toBe(false);

        // 5. Il tab prenotazioni dell'evento registra il check-in sulla riga
        await page.goto(`/eventi/${evento.evento_id}/prenotazioni`);
        const riga = page.locator('tr', { hasText: email });
        await expect(riga.locator('.badge', { hasText: 'Confermata' })).toBeVisible();
        await expect(riga.locator('.bi-qr-code-scan')).toBeVisible();
    });

    test('i KPI della dashboard admin riflettono prenotazioni e account del frontend', async ({ page }) => {
        const evento = creaEvento({ titolo: `KPI dashboard ${Date.now()}`, capienza: 5 });

        // Una prenotazione confermata fresca (entra nelle "Confermate (30 gg)")
        await accountVerificatoLoggato(page, 'kpi');
        await prenota(page, evento.evento_id);
        await expect(page.getByText(/prenotazione confermata/i)).toBeVisible();

        await nuovaSessione(page);
        await loginAdmin(page);
        await page.goto('/dashboard');

        await expect(page.getByRole('heading', { name: 'Prenotazioni frontend' })).toBeVisible();

        const confermate = page
            .locator('.metric-cell', { hasText: 'Confermate (30 gg)' })
            .locator('.metric-value');
        await expect(confermate).toBeVisible();
        expect(Number(await confermate.innerText())).toBeGreaterThanOrEqual(1);

        const account = page
            .locator('.metric-cell', { hasText: 'Account registrati' })
            .locator('.metric-value');
        expect(Number(await account.innerText())).toBeGreaterThanOrEqual(1);
    });
});
