import { expect, test } from '@playwright/test';
import {
    SLUG,
    accountVerificatoLoggato,
    azzeraThrottle,
    creaEvento,
    emailUnica,
    fixture,
    idPrenotazioneDaEsito,
    loginAccount,
    loginAdmin,
    nuovaSessione,
    prenota,
    statoPrenotazioni,
} from '../helpers';

/** Data futura nel formato datetime-local (YYYY-MM-DDTHH:mm). */
function dataFuturaLocale(giorni: number): string {
    const data = new Date(Date.now() + giorni * 24 * 60 * 60 * 1000);
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${data.getFullYear()}-${pad(data.getMonth() + 1)}-${pad(data.getDate())}T${pad(data.getHours())}:${pad(data.getMinutes())}`;
}

test.describe('Gestionale admin', () => {
    test.beforeEach(() => azzeraThrottle());

    test('admin crea e pubblica un evento → compare nel catalogo pubblico', async ({ page }) => {
        const titolo = `Evento da admin ${Date.now()}`;

        await loginAdmin(page);
        await page.goto('/eventi');
        await page.locator('#btnNuovoEvento').click();

        await page.locator('#titolo').fill(titolo);
        await page.locator('#data_evento').fill(dataFuturaLocale(14));
        // Sezione "Pubblicazione frontend": stato, capienza e prezzo
        await page.locator('#stato_pubblicazione').selectOption('pubblicato');
        await page.locator('#capienza').fill('10');
        await page.locator('#prezzo').fill('5');
        await page.locator('#eventoModal').getByRole('button', { name: /crea/i }).click();

        // L'evento è in lista nell'admin...
        await expect(page).toHaveURL(/\/eventi/);
        await expect(page.getByText(titolo)).toBeVisible();

        // ...e pubblicato nel catalogo del frontend
        await page.goto(`/a/${SLUG}/eventi`);
        const card = page.locator('.evento-card', { hasText: titolo });
        await expect(card).toBeVisible();
        await expect(card.getByText('€ 5,00')).toBeVisible();
    });

    test("tab prenotazioni dell'evento: la prenotazione c'è e l'annullo admin arriva all'account", async ({ page }) => {
        const evento = creaEvento({ titolo: `Annullo admin ${Date.now()}`, capienza: 5 });
        const email = await accountVerificatoLoggato(page, 'annullo-admin');
        await prenota(page, evento.evento_id);
        await expect(page.getByText(/prenotazione confermata/i)).toBeVisible();
        const idPrenotazione = idPrenotazioneDaEsito(page);
        const codice = statoPrenotazioni(email)[0].codice;

        // Admin: il tab prenotazioni dell'evento mostra la prenotazione
        await nuovaSessione(page);
        await loginAdmin(page);
        await page.goto(`/eventi/${evento.evento_id}/prenotazioni`);

        const riga = page.locator('tr', { hasText: email });
        await expect(riga).toBeVisible();
        await expect(riga.getByText(codice)).toBeVisible();
        await expect(riga.locator('.badge', { hasText: 'Confermata' })).toBeVisible();

        // Annulla dall'admin (conferma il dialog)
        page.once('dialog', (dialog) => dialog.accept());
        await riga.locator('button[title="Annulla prenotazione"]').click();
        await expect(page.locator('tr', { hasText: email }).locator('.badge', { hasText: 'Annullata' })).toBeVisible();

        // Lato account la prenotazione risulta annullata
        expect(statoPrenotazioni(email)[0].stato).toBe('annullata');

        await nuovaSessione(page);
        await loginAccount(page, email);
        await page.goto(`/a/${SLUG}/account/prenotazioni/${idPrenotazione}`);
        await expect(page.locator('.badge', { hasText: 'Annullata' })).toBeVisible();
        await expect(page.getByText('Annullata il')).toBeVisible();
    });

    test('pagina accounts con dettaglio e dashboard con i KPI prenotazioni', async ({ page }) => {
        const email = emailUnica('admin-elenco');
        fixture('crea-account', { email });

        await loginAdmin(page);

        // Elenco account: ricerca per email e riga visibile
        await page.goto('/accounts');
        await page.getByPlaceholder(/cerca per nome, cognome o email/i).fill(email);
        await page.getByRole('button', { name: 'Cerca' }).click();

        const riga = page.locator('tr', { hasText: email });
        await expect(riga).toBeVisible();
        await expect(riga.locator('.badge', { hasText: 'Verificata' })).toBeVisible();

        // Dettaglio raggiungibile
        await riga.getByTitle('Dettaglio').click();
        await expect(page.getByText(email)).toBeVisible();
        await expect(page.getByText('Anagrafica', { exact: true })).toBeVisible();

        // Dashboard: KPI prenotazioni frontend (frontend_pubblico_attivo = true)
        await page.goto('/dashboard');
        await expect(page.getByRole('heading', { name: 'Prenotazioni frontend' })).toBeVisible();
        await expect(page.getByText('Confermate (30 gg)')).toBeVisible();
        await expect(page.getByText('Account registrati')).toBeVisible();
    });
});
