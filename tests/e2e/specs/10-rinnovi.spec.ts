import { expect, test } from '@playwright/test';
import {
    azzeraThrottle,
    creaSocioConTessera,
    emailUnica,
    fixture,
    loginAdmin,
    loginSocio,
    SOCIO_PASSWORD,
} from '../helpers';

const ANNO = new Date().getFullYear();

/**
 * Modulo rinnovi (f.1–f.6) + ricevute/anteprime email (f.18–f.21):
 * rinnovo self-service fino al link di pagamento, campagna rinnovi con
 * invio simulato (MAIL_MAILER=log), export tesseramento, stampa massiva,
 * ricevuta quota numerata dall'area soci e centro anteprime email admin.
 */
test.describe('Rinnovi, ricevute e anteprime email', () => {
    test.beforeEach(() => azzeraThrottle());

    test('rinnovo self-service dall\'area soci: dal pulsante alla pagina di pagamento', async ({ page }) => {
        const email = emailUnica('rinnovo-self');
        creaSocioConTessera(email, { 'senza-tessera': 1, password: SOCIO_PASSWORD });

        await loginSocio(page, email);

        const bottone = page.getByRole('button', { name: `Rinnova per il ${ANNO}` });
        await expect(bottone).toBeVisible();
        await bottone.click();

        // Redirect alla pagina pubblica di pagamento con il payment_token della quota
        await expect(page).toHaveURL(/\/pagamento\/[a-f0-9]{64}$/);
        await expect(page.getByText('Socio E2E')).toBeVisible();
        await expect(page.getByText(String(ANNO)).first()).toBeVisible();
        await expect(page.locator('.importo-big')).toBeVisible();
    });

    test('rinnovo self-service non offerto se la tessera dell\'anno è già emessa', async ({ page }) => {
        const email = emailUnica('rinnovo-non-dovuto');
        creaSocioConTessera(email, { password: SOCIO_PASSWORD }); // tessera anno corrente già attiva

        await loginSocio(page, email);

        await expect(page.getByRole('heading', { name: 'Ciao, Socio!' })).toBeVisible();
        await expect(page.getByRole('button', { name: /rinnova per il/i })).toHaveCount(0);
    });

    test('campagna rinnovi: socio senza tessera in lista e invio simulato delle email', async ({ page }) => {
        const email = emailUnica('campagna');
        const socio = creaSocioConTessera(email, { 'senza-tessera': 1 });

        await loginAdmin(page);
        await page.goto('/campagna-rinnovi');
        await expect(page.getByRole('heading', { name: `Campagna rinnovi ${ANNO}` })).toBeVisible();

        // Il socio appena creato è tra i soci da rinnovare, con la sua email
        const riga = page.locator('tr', { hasText: email });
        await expect(riga).toBeVisible();
        await expect(riga.getByText(`n. ${socio.numero_socio}`)).toBeVisible();

        await riga.locator(`input.socio-check[value="${socio.socio_id}"]`).check();
        await page.getByRole('button', { name: 'Invia email di rinnovo ai selezionati' }).click();

        // MAIL_MAILER=log + QUEUE sync: l'invio è simulato ma il flusso è quello reale
        await expect(page.getByText(`Campagna rinnovi ${ANNO}: 1 email accodate.`)).toBeVisible();
        await expect(page.locator('tr', { hasText: email }).getByText('Inviata', { exact: false })).toBeVisible();
    });

    test('export Excel dello storico tesseramento del singolo socio', async ({ page }) => {
        const socio = creaSocioConTessera(emailUnica('storico-export'));

        await loginAdmin(page);
        const risposta = await page.request.get(`/soci/${socio.socio_id}/storico-tesseramento/export`);

        expect(risposta.status()).toBe(200);
        expect(risposta.headers()['content-type']).toContain('spreadsheetml');
    });

    test('stampa massiva tessere: un unico PDF con le tessere dell\'anno', async ({ page }) => {
        creaSocioConTessera(emailUnica('stampa-massiva')); // garantisce almeno una tessera

        await loginAdmin(page);
        const risposta = await page.request.get(`/tessere-stampa-massiva?anno=${ANNO}`);

        expect(risposta.status()).toBe(200);
        expect(risposta.headers()['content-type']).toContain('application/pdf');
    });

    test('ricevuta quota numerata scaricabile dall\'area soci solo se pagata', async ({ page }) => {
        const email = emailUnica('ricevuta');
        const socio = creaSocioConTessera(email, { 'con-quota': 1, password: SOCIO_PASSWORD });

        await loginSocio(page, email);

        // Quota ancora da pagare: nessuna ricevuta (404 uniforme)
        const negato = await page.request.get(`/area-soci/quote/${socio.quota_id}/ricevuta`);
        expect(negato.status()).toBe(404);

        // Pagamento registrato: la ricevuta PDF viene emessa on demand
        fixture('paga-quota', { quota: socio.quota_id! });

        await page.goto('/area-soci/quote');
        await expect(page.getByText('Pagata').first()).toBeVisible();

        const risposta = await page.request.get(`/area-soci/quote/${socio.quota_id}/ricevuta`);
        expect(risposta.status()).toBe(200);
        expect(risposta.headers()['content-type']).toContain('application/pdf');
        expect(risposta.headers()['content-disposition']).toContain('.pdf');
    });

    test('centro anteprime email: catalogo, anteprima renderizzata e invio di prova', async ({ page }) => {
        await loginAdmin(page);

        await page.goto('/email/anteprime');
        await expect(page.getByRole('heading', { name: 'Anteprime email' })).toBeVisible();
        await expect(page.locator('iframe')).toBeVisible();

        // Anteprima di una notifica specifica su dati di esempio
        await page.goto('/email/anteprime?codice=ricevuta_quota');
        await expect(page.locator('iframe')).toBeVisible();

        const html = await page.request.get('/email/anteprime/ricevuta_quota');
        expect(html.status()).toBe(200);
        expect(html.headers()['content-type']).toContain('text/html');

        // Invio di prova a sé stessi (MAIL_MAILER=log)
        await page.getByRole('button', { name: 'Invia prova a me' }).click();
        await expect(page.getByText(/Email di prova inviata a/).first()).toBeVisible();
    });
});
