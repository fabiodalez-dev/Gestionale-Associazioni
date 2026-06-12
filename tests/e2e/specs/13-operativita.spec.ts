import { expect, test } from '@playwright/test';
import {
    azzeraThrottle,
    creaSocioConTessera,
    dataUtc,
    emailUnica,
    fixture,
    loginAdmin,
} from '../helpers';

/**
 * Modulo operativita (f.1–f.6 del piano "Gestione 2"): command palette ⌘K,
 * azioni in blocco sulla lista soci, note interne sulla scheda socio,
 * attività admin con widget in dashboard e registro modifiche con diff.
 */
test.describe('Operatività quotidiana (Gestione 2, sezione A)', () => {
    test.beforeEach(() => azzeraThrottle());

    test('command palette: apertura dal bottone, ricerca di un socio e navigazione alla scheda', async ({ page }) => {
        const email = emailUnica('palette');
        const socio = creaSocioConTessera(email);

        await loginAdmin(page);

        // Apertura con il bottone lente della sidebar (alternativa a ⌘K)
        await page.locator('#globalSearchBtn').click();
        await expect(page.locator('#globalSearchModal')).toBeVisible();
        await expect(page.getByText('Digita almeno 2 caratteri.')).toBeVisible();

        // Ricerca per email (unica per run): gruppo "Soci" con numero socio
        await page.locator('#globalSearchInput').fill(email);
        const risultato = page.locator('a.gs-item', { hasText: email }).first();
        await expect(risultato).toBeVisible();
        await expect(risultato).toContainText('E2E Socio');
        await expect(risultato).toContainText(`N. ${socio.numero_socio}`);

        // Click sul risultato → scheda del socio
        await risultato.click();
        await expect(page).toHaveURL(new RegExp(`/soci/${socio.socio_id}`));
        await expect(page.locator('#tab-anagrafica').getByText(email)).toBeVisible();
    });

    test('azioni in blocco: cambio stato di due soci selezionati con conferma e audit', async ({ page }) => {
        // Marker comune nelle email per filtrare la lista su entrambi i soci
        const tag = `blocco${Date.now()}`;
        const emailA = `${tag}-a@example.com`;
        const emailB = `${tag}-b@example.com`;
        // Entrambi con tessera attiva: la lista soci filtra di default
        // su tessera_stato=attive
        creaSocioConTessera(emailA);
        creaSocioConTessera(emailB);

        await loginAdmin(page);
        await page.goto(`/soci?search=${tag}`);

        const checkbox = page.locator('#sociResults .socio-check');
        await expect(checkbox).toHaveCount(2);
        await checkbox.nth(0).check();
        await checkbox.nth(1).check();

        // La barra azioni compare solo con una selezione attiva
        await expect(page.locator('#bulkBar')).toBeVisible();
        await expect(page.locator('#bulkCount')).toHaveText('2');

        await page.locator('#bulkAzione').selectOption('stato');
        await page.locator('#bulkValore').selectOption('Sospeso');
        await page.locator('#bulkApplica').click();

        // Modale di conferma con riepilogo prima dell'applicazione
        await expect(page.locator('#bulkConfermaModal')).toBeVisible();
        await expect(page.locator('#bulkRiepilogoAzione')).toHaveText('Cambia stato');
        await expect(page.locator('#bulkRiepilogoValore')).toHaveText('Sospeso');
        await expect(page.locator('#bulkRiepilogoCount')).toHaveText('2');
        await page.locator('#bulkConfermaSubmit').click();

        await expect(page.getByText('Cambio stato in "Sospeso" applicata a 2 soci.')).toBeVisible();

        // Lo stato è stato effettivamente cambiato su entrambi
        expect(fixture<{ stato: string }>('stato-socio', { email: emailA }).stato).toBe('Sospeso');
        expect(fixture<{ stato: string }>('stato-socio', { email: emailB }).stato).toBe('Sospeso');
    });

    test('note interne: aggiunta con pin, timeline ed eliminazione', async ({ page }) => {
        const socio = creaSocioConTessera(emailUnica('nota'));
        const testoNota = `Nota interna E2E ${Date.now()}: richiamare il socio per il rinnovo.`;

        await loginAdmin(page);
        await page.goto(`/soci/${socio.socio_id}?tab=note`);

        await page.locator('#nota_testo').fill(testoNota);
        await page.locator('#nota_fissata').check();
        await page.getByRole('button', { name: 'Aggiungi' }).click();

        await expect(page.getByText('Nota aggiunta.')).toBeVisible();
        await expect(page.getByText(testoNota)).toBeVisible();
        await expect(page.locator('.badge', { hasText: 'Fissata' })).toBeVisible();

        // Eliminazione (dialog di conferma nativo)
        page.once('dialog', (dialog) => dialog.accept());
        await page.locator('button[title="Elimina nota"]').click();

        await expect(page.getByText('Nota eliminata.')).toBeVisible();
        await expect(page.getByText('Nessuna nota interna su questo socio.')).toBeVisible();
    });

    test('attività admin: creazione con scadenza oggi, widget in dashboard e completamento', async ({ page }) => {
        const titolo = `Attività E2E ${Date.now()}`;

        await loginAdmin(page);
        await page.goto('/attivita-admin');
        await expect(page.getByRole('heading', { name: 'Attività' })).toBeVisible();

        await page.getByRole('button', { name: 'Nuova attività' }).click();
        await page.locator('#titolo-new').fill(titolo);
        await page.locator('#scadenza-new').fill(dataUtc(0));
        await page.getByRole('button', { name: 'Crea attività' }).click();

        await expect(page.getByText('Attività creata.')).toBeVisible();
        const riga = page.locator('tr', { hasText: titolo }).first();
        await expect(riga.locator('.badge', { hasText: 'Oggi' })).toBeVisible();

        // Widget "Le mie attività" in dashboard (non assegnata → visibile a tutti)
        await page.goto('/dashboard');
        const widget = page.locator('.card', { hasText: 'Le mie attività' });
        await expect(widget.getByText(titolo)).toBeVisible();
        await expect(widget.getByText('non assegnata').first()).toBeVisible();

        // Completamento dalla lista
        await page.goto('/attivita-admin');
        await page.locator('tr', { hasText: titolo }).first()
            .locator('button[title="Segna come completata"]').click();
        await expect(page.getByText('Attività completata.')).toBeVisible();
    });

    test('registro modifiche: la modifica di un\'attività compare nel log con il diff vecchio → nuovo', async ({ page }) => {
        const titoloOriginale = `Registro E2E ${Date.now()}`;
        const titoloNuovo = `${titoloOriginale} rivista`;

        await loginAdmin(page);
        await page.goto('/attivita-admin');
        await page.getByRole('button', { name: 'Nuova attività' }).click();
        await page.locator('#titolo-new').fill(titoloOriginale);
        await page.getByRole('button', { name: 'Crea attività' }).click();
        await expect(page.getByText('Attività creata.')).toBeVisible();

        // Modifica inline del titolo (form nella riga collapse)
        await page.locator('tr', { hasText: titoloOriginale }).first()
            .locator('button[title="Modifica"]').click();
        const campoTitolo = page.locator(`tr.collapse input[name="titolo"][value="${titoloOriginale}"]`);
        await expect(campoTitolo).toBeVisible();
        await campoTitolo.fill(titoloNuovo);
        await page.locator(`form:has(input[name="titolo"][value="${titoloOriginale}"])`)
            .getByRole('button', { name: 'Salva modifiche' }).click();
        await expect(page.getByText('Attività aggiornata.')).toBeVisible();

        // Registro modifiche filtrato: riga "Modifica" con diff titolo
        await page.goto(`/registro-modifiche?modello=${encodeURIComponent('Attività admin')}&azione=updated`);
        await expect(page.getByRole('heading', { name: 'Registro modifiche' })).toBeVisible();

        const rigaLog = page.locator('tr', { hasText: titoloNuovo }).first();
        await expect(rigaLog.locator('.badge', { hasText: 'Modifica' })).toBeVisible();
        await expect(rigaLog.getByText('Attività admin')).toBeVisible();
        // Diff leggibile: vecchio valore barrato → nuovo valore
        await expect(rigaLog.locator('del', { hasText: titoloOriginale })).toBeVisible();
        await expect(rigaLog.locator('.text-success', { hasText: titoloNuovo })).toBeVisible();
    });
});
