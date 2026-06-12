import { expect, test } from '@playwright/test';
import { azzeraThrottle, fixture, SLUG } from '../helpers';

/**
 * Addendum "Convocazioni assemblee + Trasparenza":
 * documenti pubblici sulla pagina /a/{slug}/trasparenza con download
 * anonimo, convocazioni di assemblea sulla pagina Comunicazioni (Novità)
 * con conferma presenza via evento collegato, voci di navigazione.
 */
test.describe('Convocazioni assemblee e Trasparenza (addendum)', () => {
    test.beforeEach(() => azzeraThrottle());

    test('documento pubblicato visibile sulla pagina Trasparenza e scaricabile senza auth', async ({ page }) => {
        const nomeFile = `bilancio-e2e-${Date.now()}.pdf`;
        const documento = fixture<{ documento_id: string; nome_file: string; categoria: string }>(
            'crea-documento-pubblico',
            { categoria: 'bilancio', titolo: nomeFile },
        );

        await page.goto(`/a/${SLUG}/trasparenza`);
        await expect(page.getByRole('heading', { name: 'Trasparenza' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Bilanci' })).toBeVisible();
        await expect(page.getByText(nomeFile)).toBeVisible();

        // Download anonimo: 200 con content-type applicativo
        const risposta = await page.request.get(`/a/${SLUG}/trasparenza/${documento.documento_id}/download`);
        expect(risposta.status()).toBe(200);
        expect(risposta.headers()['content-type']).toMatch(/application\//);
        expect((await risposta.body()).length).toBeGreaterThan(0);
    });

    test('convocazione con evento collegato visibile su Comunicazioni con conferma presenza', async ({ page }) => {
        const titolo = `Convocazione E2E ${Date.now()}`;
        const convocazione = fixture<{ annuncio_id: string; titolo: string; evento_id: string }>(
            'crea-convocazione',
            { titolo },
        );

        await page.goto(`/a/${SLUG}/novita`);
        await expect(page.getByRole('heading', { name: titolo })).toBeVisible();

        const card = page.locator('.card', { hasText: titolo });
        await expect(card.getByText('Convocazione assemblea')).toBeVisible();
        await expect(card.getByText('Sede E2E, Via dei Test 1')).toBeVisible();
        await expect(card.getByText(/Ordine del giorno/)).toBeVisible();

        // Bottone di conferma presenza → pagina pubblica dell'evento collegato
        const conferma = card.getByRole('link', { name: 'Conferma la tua presenza' });
        await expect(conferma).toBeVisible();
        await expect(conferma).toHaveAttribute('href', new RegExp(`/eventi/${convocazione.evento_id}$`));

        await conferma.click();
        await expect(page).toHaveURL(new RegExp(`/a/${SLUG}/eventi/${convocazione.evento_id}`));
    });

    test('la navigazione del mini-sito contiene Comunicazioni e Trasparenza', async ({ page }) => {
        await page.goto(`/a/${SLUG}`);

        const nav = page.locator('nav.pubblico-navbar');
        await expect(nav.getByRole('link', { name: 'Comunicazioni' })).toHaveAttribute(
            'href',
            new RegExp(`/a/${SLUG}/novita$`),
        );
        await expect(nav.getByRole('link', { name: 'Trasparenza' })).toHaveAttribute(
            'href',
            new RegExp(`/a/${SLUG}/trasparenza$`),
        );

        await nav.getByRole('link', { name: 'Trasparenza' }).click();
        await expect(page.getByRole('heading', { name: 'Trasparenza' })).toBeVisible();
    });
});
