import { expect, test } from '@playwright/test';
import {
    azzeraThrottle,
    creaSocioConTessera,
    dataUtc,
    emailUnica,
    fixture,
    loginAdmin,
    SLUG,
    SUPER_ADMIN,
} from '../helpers';

/**
 * Modulo registro (f.13–f.16) + anagrafica-pro (f.22–f.25): libro soci
 * con export ufficiali, cessazioni formali, organi sociali con alert
 * mandati, slug del mini-sito editabile, QR di invito, documenti socio
 * in scadenza, rilevazione duplicati ed export Excel multi-foglio.
 */
test.describe('Registro e anagrafica pro', () => {
    test.beforeEach(() => azzeraThrottle());

    test('libro soci: registro cronologico con export PDF ed Excel', async ({ page }) => {
        const socio = creaSocioConTessera(emailUnica('libro-soci'));

        await loginAdmin(page);
        await page.goto('/libro-soci');

        await expect(page.getByRole('heading', { name: 'Libro soci' })).toBeVisible();
        await expect(page.getByText(`Socio n. ${socio.numero_socio}`)).toBeVisible();

        const pdf = await page.request.get('/libro-soci/pdf');
        expect(pdf.status()).toBe(200);
        expect(pdf.headers()['content-type']).toContain('application/pdf');

        const excel = await page.request.get('/libro-soci/excel');
        expect(excel.status()).toBe(200);
        expect(excel.headers()['content-type']).toContain('spreadsheetml');
    });

    test('cessazione formale: dimissioni registrate, socio radiato e voce nel registro', async ({ page }) => {
        const email = emailUnica('cessazione');
        const socio = creaSocioConTessera(email);

        await loginAdmin(page);
        await page.goto('/cessazioni');
        await expect(page.getByRole('heading', { name: 'Cessazioni' })).toBeVisible();

        await page.getByRole('button', { name: 'Registra cessazione' }).first().click();
        await expect(page.locator('#cessazioneModal')).toBeVisible();

        await page.locator('#socio_id').selectOption(socio.socio_id);
        await page.locator('#tipo').selectOption('dimissioni');
        await page.locator('#motivo').fill('Dimissioni volontarie comunicate per iscritto (test E2E).');

        // Il submit chiede conferma con un dialog nativo
        page.once('dialog', (dialog) => dialog.accept());
        await page.locator('#cessazioneModal button[type="submit"]').click();

        await expect(page.getByText(/Cessazione di Socio E2E registrata/)).toBeVisible();
        await expect(page.locator('tr', { hasText: `Socio n. ${socio.numero_socio}` }).locator('.badge', { hasText: 'Dimissioni' })).toBeVisible();

        // Lo stato del socio è stato aggiornato dal flusso formale
        const stato = fixture<{ stato: string }>('stato-socio', { email });
        expect(stato.stato).toBe('Radiato');
    });

    test('organi sociali: nuova carica registrata e alert mandato in scadenza', async ({ page }) => {
        const socio = creaSocioConTessera(emailUnica('carica'));
        const ruolo = `Presidente E2E ${Date.now()}`;

        await loginAdmin(page);
        await page.goto('/organi-sociali');
        await expect(page.getByRole('heading', { name: 'Organi sociali' })).toBeVisible();

        await page.getByRole('button', { name: 'Nuova carica' }).click();
        await expect(page.locator('#caricaModal')).toBeVisible();

        await page.locator('#carica_socio_id').selectOption(socio.socio_id);
        await page.locator('#ruolo').fill(ruolo);
        await page.locator('#inizio').fill(dataUtc(-365));
        // Fine mandato tra 30 giorni: dentro la finestra di alert (90 giorni)
        await page.locator('#fine').fill(dataUtc(30));
        await page.locator('#caricaModal button[type="submit"]').click();

        await expect(page.getByText('Carica registrata con successo!')).toBeVisible();

        // La carica è tra le attive, con il badge e l'alert dei mandati in scadenza
        const riga = page.locator('tr', { hasText: ruolo });
        await expect(riga.getByText('In scadenza')).toBeVisible();
        const alert = page.locator('.alert-warning', { hasText: 'Mandati in scadenza' });
        await expect(alert).toBeVisible();
        await expect(alert.getByText(ruolo)).toBeVisible();
    });

    test('slug del mini-sito editabile dal form associazione (super admin)', async ({ page }) => {
        const nuovoSlug = `spenta-rinominata-${Date.now()}`;

        await loginAdmin(page, SUPER_ADMIN.email, SUPER_ADMIN.password);
        await page.goto('/associazioni');

        // Apre la modifica dell'associazione "Spenta" (non usata dagli altri test 10-12)
        await page.locator('tr', { hasText: 'Associazione Spenta' }).locator('a[title="Modifica"]').click();
        await expect(page.locator('#modalAssociazione')).toBeVisible();
        await expect(page.locator('#slug')).toHaveValue('spenta');

        await page.locator('#slug').fill(nuovoSlug);
        await page.getByRole('button', { name: 'Salva modifiche' }).click();

        await expect(page.getByText('Associazione aggiornata con successo.')).toBeVisible();

        // Riapre la modifica: lo slug persistito è quello nuovo
        await page.locator('tr', { hasText: 'Associazione Spenta' }).locator('a[title="Modifica"]').click();
        await expect(page.locator('#slug')).toHaveValue(nuovoSlug);

        // Ripristina lo slug originale per l'idempotenza del run
        await page.locator('#slug').fill('spenta');
        await page.getByRole('button', { name: 'Salva modifiche' }).click();
        await expect(page.getByText('Associazione aggiornata con successo.')).toBeVisible();
    });

    test('invito al mini-sito: link di iscrizione e QR PNG scaricabile', async ({ page }) => {
        await loginAdmin(page);
        await page.goto('/configurazioni?section=invito');

        await expect(page.getByText('Invita al tuo mini-sito')).toBeVisible();
        await expect(page.locator('#link_invito')).toHaveValue(new RegExp(`/a/${SLUG}/iscriviti$`));

        const qr = await page.request.get('/configurazioni/invito-qr');
        expect(qr.status()).toBe(200);
        expect(qr.headers()['content-type']).toContain('image/png');

        const download = await page.request.get('/configurazioni/invito-qr?download=1');
        expect(download.status()).toBe(200);
        expect(download.headers()['content-disposition']).toContain('attachment');
    });

    test('documento socio con scadenza vicina: badge "In scadenza" nella scheda', async ({ page }) => {
        const socio = creaSocioConTessera(emailUnica('documento'));

        await loginAdmin(page);
        await page.goto(`/soci/${socio.socio_id}?tab=documenti`);

        await page.locator('#doc_file').setInputFiles({
            name: 'certificato-medico.txt',
            mimeType: 'text/plain',
            buffer: Buffer.from('Certificato medico di test E2E'),
        });
        await page.locator('#doc_descrizione').fill('Certificato medico E2E');
        // Scadenza tra 10 giorni: dentro la finestra di preavviso (30 giorni)
        await page.locator('#doc_scadenza').fill(dataUtc(10));
        // L'icona Bootstrap contribuisce al nome accessibile: niente exact
        await page.getByRole('button', { name: 'Carica' }).click();

        await page.goto(`/soci/${socio.socio_id}?tab=documenti`);
        await expect(page.getByRole('cell', { name: 'certificato-medico.txt' })).toBeVisible();
        await expect(page.locator('.badge', { hasText: 'In scadenza' }).first()).toBeVisible();
    });

    test('possibili duplicati: stessi nome, cognome e data di nascita rilevati come gruppo', async ({ page }) => {
        // Email e CF sono unique per tenant: il criterio osservabile è l'anagrafica.
        // Solo questi due soci hanno la data di nascita valorizzata su questa terna.
        const emailA = emailUnica('duplicato-a');
        const emailB = emailUnica('duplicato-b');
        creaSocioConTessera(emailA, { 'data-nascita': '1990-01-01' });
        creaSocioConTessera(emailB, { 'senza-tessera': 1, 'data-nascita': '1990-01-01' });

        await loginAdmin(page);
        await page.goto('/soci-duplicati');
        await expect(page.getByRole('heading', { name: 'Possibili duplicati' })).toBeVisible();

        const gruppo = page.locator('.card', { hasText: emailA });
        await expect(gruppo.getByText('Stessi nome, cognome e data di nascita')).toBeVisible();
        await expect(gruppo.getByText(emailB)).toBeVisible();
        await expect(gruppo.getByText('2 soci', { exact: true })).toBeVisible();
        await expect(gruppo.getByRole('button', { name: 'Unisci nel principale' })).toBeVisible();
    });

    test('export Excel multi-foglio dell\'anagrafica pro', async ({ page }) => {
        creaSocioConTessera(emailUnica('export-pro'));

        await loginAdmin(page);
        await page.goto('/export-import');
        await expect(page.getByRole('heading', { name: 'Export / Import pro' })).toBeVisible();
        await expect(page.getByText('Export Excel multi-foglio')).toBeVisible();

        const excel = await page.request.get('/export-import/export');
        expect(excel.status()).toBe(200);
        expect(excel.headers()['content-type']).toContain('spreadsheetml');
        expect(excel.headers()['content-disposition']).toContain('anagrafica_');
    });
});
