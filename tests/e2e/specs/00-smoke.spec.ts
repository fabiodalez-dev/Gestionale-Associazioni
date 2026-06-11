import { expect, test } from '@playwright/test';
import { SLUG, accountVerificatoLoggato, creaEvento } from '../helpers';

test('smoke: catalogo raggiungibile e prenotazione di base', async ({ page }) => {
    const evento = creaEvento({ titolo: 'Smoke Test', capienza: 5 });

    await page.goto(`/a/${SLUG}/eventi`);
    await expect(page.getByRole('heading', { level: 1 })).toContainText(/eventi/i);
    await expect(page.getByText('Smoke Test')).toBeVisible();

    await accountVerificatoLoggato(page, 'smoke');
    await page.goto(`/a/${SLUG}/eventi/${evento.evento_id}`);
    await page.getByRole('button', { name: /prenota/i }).click();
    await expect(page.getByText(/prenotazione confermata/i)).toBeVisible();
    await expect(page.getByText(/PRN-/)).toBeVisible();
});
