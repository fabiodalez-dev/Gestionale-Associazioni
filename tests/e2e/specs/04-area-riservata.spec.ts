import { expect, test } from '@playwright/test';
import {
    ACCOUNT_PASSWORD,
    SLUG,
    accountVerificatoLoggato,
    azzeraThrottle,
    creaEvento,
    fixture,
    loginAccount,
    nuovaSessione,
    prenota,
} from '../helpers';

test.describe('Area riservata account', () => {
    test.beforeEach(() => azzeraThrottle());

    test('dashboard: la prossima prenotazione è visibile con il titolo evento', async ({ page }) => {
        const evento = creaEvento({ titolo: `Prossimo evento ${Date.now()}`, capienza: 5 });
        await accountVerificatoLoggato(page, 'dashboard');
        await prenota(page, evento.evento_id);
        await expect(page.getByText(/prenotazione confermata/i)).toBeVisible();

        await page.goto(`/a/${SLUG}/account`);

        const cardProssime = page.locator('.card', { hasText: 'Prossime prenotazioni' });
        await expect(cardProssime.getByText(evento.titolo)).toBeVisible();
        await expect(cardProssime.locator('.badge', { hasText: 'Confermata' })).toBeVisible();
    });

    test('storico: un evento passato con prenotazione compare nello storico', async ({ page }) => {
        const evento = creaEvento({ titolo: `Evento concluso ${Date.now()}`, capienza: 5 });
        await accountVerificatoLoggato(page, 'storico');
        await prenota(page, evento.evento_id);
        await expect(page.getByText(/prenotazione confermata/i)).toBeVisible();

        // L'evento viene spostato nel passato (il service blocca le date passate
        // in prenotazione, quindi si prenota prima e si sposta poi)
        fixture('sposta-evento-passato', { evento: evento.evento_id });

        await page.goto(`/a/${SLUG}/account/storico`);

        const riga = page.locator('tr', { hasText: evento.titolo });
        await expect(riga).toBeVisible();
        await expect(riga.locator('.badge', { hasText: 'Confermata' })).toBeVisible();
        await expect(riga.getByText('Nessun check-in')).toBeVisible();
    });

    test('profilo: cambio password e nuovo login con la nuova password', async ({ page }) => {
        const email = await accountVerificatoLoggato(page, 'cambio-password');
        const nuovaPassword = 'NuovaPassword2026';

        await page.goto(`/a/${SLUG}/account/profilo`);
        await page.locator('#password_corrente').fill(ACCOUNT_PASSWORD);
        await page.locator('#password').fill(nuovaPassword);
        await page.locator('#password_confirmation').fill(nuovaPassword);
        await page.getByRole('button', { name: 'Aggiorna password' }).click();
        await expect(page.getByText('Password aggiornata.')).toBeVisible();

        // Logout forzato e login con la NUOVA password
        await nuovaSessione(page);
        await loginAccount(page, email, nuovaPassword);
        await expect(page.getByRole('button', { name: /account e2e/i })).toBeVisible();
    });

    test('preferenze: i toggle salvati sopravvivono al reload', async ({ page }) => {
        await accountVerificatoLoggato(page, 'preferenze');

        await page.goto(`/a/${SLUG}/account/preferenze`);

        // Stato di partenza (default): newsletter off, promemoria on, marketing off
        await expect(page.locator('#newsletter')).not.toBeChecked();
        await expect(page.locator('#promemoria_eventi')).toBeChecked();
        await expect(page.locator('#marketing')).not.toBeChecked();

        // Inverte tutti i toggle e salva
        await page.locator('#newsletter').check();
        await page.locator('#promemoria_eventi').uncheck();
        await page.locator('#marketing').check();
        await page.getByRole('button', { name: 'Salva preferenze' }).click();
        await expect(page.getByText('Preferenze salvate.')).toBeVisible();

        // Ricaricando la pagina le scelte restano
        await page.goto(`/a/${SLUG}/account/preferenze`);
        await expect(page.locator('#newsletter')).toBeChecked();
        await expect(page.locator('#promemoria_eventi')).not.toBeChecked();
        await expect(page.locator('#marketing')).toBeChecked();
    });

    test('GDPR: export JSON con la propria email e cancellazione definitiva', async ({ page }) => {
        const email = await accountVerificatoLoggato(page, 'gdpr');

        // Export: JSON scaricabile che contiene l'email dell'account
        const exportRisposta = await page.request.get(`/a/${SLUG}/account/privacy/export`);
        expect(exportRisposta.status()).toBe(200);
        expect(exportRisposta.headers()['content-type']).toContain('application/json');
        const dati = await exportRisposta.json();
        expect(dati.account.email).toBe(email);

        // Cancellazione con conferma password (dialog confirm accettato)
        await page.goto(`/a/${SLUG}/account/privacy`);
        page.once('dialog', (dialog) => dialog.accept());
        await page.locator('#password').fill(ACCOUNT_PASSWORD);
        await page.getByRole('button', { name: /cancella definitivamente/i }).click();

        await expect(page.getByText(/il tuo account è stato cancellato/i)).toBeVisible();

        // Il login successivo con le vecchie credenziali è rifiutato
        await page.goto(`/a/${SLUG}/login`);
        await page.locator('#email').fill(email);
        await page.locator('#password').fill(ACCOUNT_PASSWORD);
        await page.getByRole('button', { name: /accedi/i }).click();
        await expect(page.getByText('Credenziali non valide.').first()).toBeVisible();
    });
});
