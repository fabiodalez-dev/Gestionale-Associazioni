import { expect, test } from '@playwright/test';
import {
    SLUG,
    accountVerificatoLoggato,
    azzeraThrottle,
    configuraFormIscrizione,
    inviaIscrizione,
    loginAdmin,
    nuovaSessione,
    statoRichiesta,
} from '../helpers';

test.describe("Iscrizione all'associazione dal form pubblico", () => {
    test.beforeEach(() => {
        azzeraThrottle();
        // Stato noto per ogni test: iscrizioni aperte, preiscrizione, config default
        configuraFormIscrizione();
    });

    test('admin configura il modulo: toggle campi e salvataggio persistente', async ({ page }) => {
        await loginAdmin(page);
        await page.goto('/configurazioni/modulo-iscrizione');

        await expect(page.getByRole('heading', { name: 'Modulo di iscrizione' })).toBeVisible();
        await expect(page.getByRole('link', { name: /anteprima form pubblico/i })).toBeVisible();

        // Mostra "Indirizzo" (default nascosto) e rende facoltativo "Telefono"
        await page.locator('#std_indirizzo_visibile').check();
        await page.locator('#std_telefono_obbligatorio').uncheck();
        await page.getByRole('button', { name: /salva modulo di iscrizione/i }).click();

        await expect(page.getByText('Modulo di iscrizione salvato.')).toBeVisible();

        // Le scelte sono persistite alla ricarica
        await page.goto('/configurazioni/modulo-iscrizione');
        await expect(page.locator('#std_indirizzo_visibile')).toBeChecked();
        await expect(page.locator('#std_telefono_obbligatorio')).not.toBeChecked();
    });

    test('il form pubblico mostra solo i campi configurati (con intro)', async ({ page }) => {
        configuraFormIscrizione({
            intro: '<p>Benvenuto nel modulo E2E</p>',
            config: JSON.stringify({
                campi_standard: [
                    { campo: 'telefono', visibile: true, obbligatorio: true },
                    { campo: 'citta', visibile: true, obbligatorio: false },
                    { campo: 'cap', visibile: false, obbligatorio: false },
                ],
            }),
        });
        await accountVerificatoLoggato(page, 'form-campi');

        await page.goto(`/a/${SLUG}/iscriviti`);

        await expect(page.getByRole('heading', { name: /iscriviti a associazione e2e/i })).toBeVisible();
        await expect(page.getByText('Benvenuto nel modulo E2E')).toBeVisible();
        await expect(page.locator('#campo-telefono')).toBeVisible();
        await expect(page.locator('#campo-citta')).toBeVisible();
        // I campi non configurati o nascosti non sono renderizzati
        await expect(page.locator('#campo-cap')).toHaveCount(0);
        await expect(page.locator('#campo-data_nascita')).toHaveCount(0);
    });

    test('campo obbligatorio mancante → errore di validazione sul campo', async ({ page }) => {
        const email = await accountVerificatoLoggato(page, 'obbligatorio');

        // Default: telefono e data di nascita obbligatori → si invia tutto vuoto
        await inviaIscrizione(page);

        await expect(page.locator('#campo-telefono.is-invalid')).toBeVisible();
        await expect(page.locator('#campo-data_nascita.is-invalid')).toBeVisible();

        // Nessuna richiesta creata
        expect(() => statoRichiesta(email)).toThrow();
    });

    test('telefono non valido → errore, la richiesta non parte', async ({ page }) => {
        await accountVerificatoLoggato(page, 'tel-invalido');

        await inviaIscrizione(page, { telefono: '123', data_nascita: '1990-05-10' });

        const errore = page.locator('#campo-telefono.is-invalid ~ .invalid-feedback');
        await expect(errore).toBeVisible();
    });

    test('codice fiscale non valido → errore, la richiesta non parte', async ({ page }) => {
        await accountVerificatoLoggato(page, 'cf-invalido');

        await inviaIscrizione(page, {
            telefono: '+39 333 1234567',
            data_nascita: '1990-05-10',
            codice_fiscale: 'AAABBB00C00D000X',
        });

        const errore = page.locator('#campo-codice_fiscale.is-invalid ~ .invalid-feedback');
        await expect(errore).toBeVisible();
    });

    test('flusso felice: richiesta inviata → stato "in attesa" su form e dashboard account', async ({ page }) => {
        const email = await accountVerificatoLoggato(page, 'flusso-felice');

        await inviaIscrizione(page, { telefono: '+39 333 1234567', data_nascita: '1991-02-03', citta: 'Milano' });

        await expect(page.getByText('Richiesta di iscrizione inviata!')).toBeVisible();

        // Rivisitando /iscriviti si vede la pagina di stato, non il form
        await page.goto(`/a/${SLUG}/iscriviti`);
        await expect(page.getByRole('heading', { name: 'Richiesta in attesa di approvazione' })).toBeVisible();

        // Dashboard account: badge "Richiesta in attesa"
        await page.goto(`/a/${SLUG}/account`);
        await expect(page.locator('.badge', { hasText: 'Richiesta in attesa' })).toBeVisible();

        // Lato dati: richiesta in_attesa, socio preiscritto "In Attesa"
        const richiesta = statoRichiesta(email);
        expect(richiesta.stato).toBe('in_attesa');
        expect(richiesta.socio_stato).toBe('In Attesa');
        expect(richiesta.account_socio_id).toBeNull();
    });

    test('admin approva con tessera → l\'account vede la tessera nella sua area', async ({ page }) => {
        const email = await accountVerificatoLoggato(page, 'approvazione');
        await inviaIscrizione(page, { telefono: '+39 333 7654321', data_nascita: '1985-07-21' });
        await expect(page.getByText('Richiesta di iscrizione inviata!')).toBeVisible();

        // Admin: approva dalla pagina Richieste iscrizione spuntando "genera tessera"
        await nuovaSessione(page);
        await loginAdmin(page);
        await page.goto('/richieste-iscrizione');

        const riga = page.locator('tr', { hasText: email });
        await expect(riga).toBeVisible();
        await riga.getByRole('button', { name: /approva/i }).click();

        const modale = page.locator('#modalApprova');
        await expect(modale).toBeVisible();
        await modale.locator('#genera_tessera').check();
        await modale.getByRole('button', { name: /approva/i }).click();

        // Il flash compare due volte nel layout admin: basta la prima occorrenza
        await expect(page.getByText(/richiesta approvata/i).first()).toBeVisible();

        // Lato dati: socio Attivo e account collegato
        const richiesta = statoRichiesta(email);
        expect(richiesta.stato).toBe('approvata');
        expect(richiesta.socio_stato).toBe('Attivo');
        expect(richiesta.account_socio_id).not.toBeNull();

        // L'account vede tessera e QR nella pagina "Tessera & quote"
        await nuovaSessione(page);
        await page.goto(`/a/${SLUG}/login`);
        await page.getByLabel(/email/i).fill(email);
        await page.getByLabel(/password/i).fill('AccountE2e2026');
        await page.getByRole('button', { name: /accedi/i }).click();

        await page.goto(`/a/${SLUG}/account/tessera`);
        await expect(page.getByText(/tessera n\./i)).toBeVisible();
        await expect(page.locator('img[alt^="QR tessera"]')).toBeVisible();
    });

    test('rifiuto con motivo → l\'account vede il motivo e può riprovare', async ({ page }) => {
        const email = await accountVerificatoLoggato(page, 'rifiuto');
        await inviaIscrizione(page, { telefono: '+39 333 9988776', data_nascita: '1979-11-30' });
        await expect(page.getByText('Richiesta di iscrizione inviata!')).toBeVisible();

        // Admin rifiuta con motivo obbligatorio dalla modale
        await nuovaSessione(page);
        await loginAdmin(page);
        await page.goto('/richieste-iscrizione');

        const riga = page.locator('tr', { hasText: email });
        await riga.getByRole('button', { name: /rifiuta/i }).click();

        const modale = page.locator('#modalRifiuta');
        await expect(modale).toBeVisible();
        await modale.locator('#motivo_rifiuto').fill('Documentazione incompleta E2E');
        await modale.getByRole('button', { name: /rifiuta/i }).click();

        await expect(page.getByText(/richiesta rifiutata/i).first()).toBeVisible();

        const richiesta = statoRichiesta(email);
        expect(richiesta.stato).toBe('rifiutata');
        expect(richiesta.motivo_rifiuto).toBe('Documentazione incompleta E2E');

        // Account: badge e motivo in dashboard, alert sul form per riprovare
        await nuovaSessione(page);
        await page.goto(`/a/${SLUG}/login`);
        await page.getByLabel(/email/i).fill(email);
        await page.getByLabel(/password/i).fill('AccountE2e2026');
        await page.getByRole('button', { name: /accedi/i }).click();

        await page.goto(`/a/${SLUG}/account`);
        await expect(page.locator('.badge', { hasText: 'Richiesta rifiutata' })).toBeVisible();
        await expect(page.getByText('Documentazione incompleta E2E')).toBeVisible();

        await page.goto(`/a/${SLUG}/iscriviti`);
        await expect(page.getByText(/la tua richiesta precedente è stata rifiutata/i)).toBeVisible();
        await expect(page.getByText('Documentazione incompleta E2E')).toBeVisible();
        await expect(page.getByRole('button', { name: /invia la richiesta di iscrizione/i })).toBeVisible();
    });

    test('iscrizioni chiuse → pagina dedicata al posto del form', async ({ page }) => {
        configuraFormIscrizione({ aperte: 0 });
        await accountVerificatoLoggato(page, 'chiuse');

        await page.goto(`/a/${SLUG}/iscriviti`);

        await expect(page.getByRole('heading', { name: 'Le iscrizioni sono chiuse' })).toBeVisible();
        await expect(page.getByRole('button', { name: /invia la richiesta/i })).toHaveCount(0);
    });

    test('modalità diretta → subito socio Attivo con account collegato', async ({ page }) => {
        configuraFormIscrizione({ modalita: 'diretta' });
        const email = await accountVerificatoLoggato(page, 'diretta');

        await inviaIscrizione(page, { telefono: '+39 333 5544332', data_nascita: '2000-01-15' });

        // Redirect diretto alla pagina tessera con il benvenuto
        await expect(page).toHaveURL(new RegExp(`/a/${SLUG}/account/tessera`));
        await expect(page.getByText(/ora sei socio di Associazione E2E/i)).toBeVisible();

        const richiesta = statoRichiesta(email);
        expect(richiesta.stato).toBe('approvata');
        expect(richiesta.socio_stato).toBe('Attivo');
        expect(richiesta.account_socio_id).not.toBeNull();
    });
});
