import { expect, test } from '@playwright/test';
import { azzeraThrottle, fixture, loginAdmin } from '../helpers';

/**
 * Round 3 "Organizzazione" — sezioni C (Progetti) e D (Riunioni & decisioni).
 *
 * Copre i flussi end-to-end:
 *  - progetto con movimento di prima nota collegato e rendiconto (f.12/f.13);
 *  - milestone: aggiunta e completamento con barra di avanzamento (f.16);
 *  - riunione: convocazione agli intestatari delle cariche, votazione su un
 *    punto OdG e generazione bozza verbale numerata (f.17/f.18);
 *  - pagina Decisioni che aggrega le votazioni approvate (f.19);
 *  - adempimento ricorrente spuntato nello Scadenzario (f.20).
 */

type FixturaProgetto = { progetto_id: string; nome: string };
type FixturaRiunione = { riunione_id: string; titolo: string; stato: string };
type StatoRiunione = {
    verbale_id: string | null;
    verbale_numero: string | null;
    votazioni: number;
    partecipanti: number;
};

test.describe('Progetti e riunioni (Organizzazione 3 — C/D)', () => {
    test.beforeEach(() => azzeraThrottle());

    test('progetto con movimento collegato e rendiconto economico (f.12/f.13)', async ({ page }) => {
        const progetto = fixture<FixturaProgetto>('crea-progetto', { titolo: `Festival ${Date.now()}`, budget: 1000 });
        fixture('crea-movimento-progetto', { progetto: progetto.progetto_id, tipo: 'entrata', importo: 250 });
        fixture('crea-movimento-progetto', { progetto: progetto.progetto_id, tipo: 'uscita', importo: 100 });

        await loginAdmin(page);
        await page.goto(`/progetti/${progetto.progetto_id}`);

        await expect(page.getByRole('heading', { name: progetto.nome })).toBeVisible();
        const rendiconto = page.locator('.card', { hasText: 'Rendiconto economico' });
        await expect(rendiconto.getByText('250,00')).toBeVisible(); // entrate
        await expect(rendiconto.getByText('100,00')).toBeVisible(); // uscite
        await expect(rendiconto.getByText('150,00')).toBeVisible(); // saldo

        // Report PDF del progetto: 200 application/pdf
        const risposta = await page.request.get(`/progetti/${progetto.progetto_id}/report-pdf`);
        expect(risposta.status()).toBe(200);
        expect(risposta.headers()['content-type']).toContain('application/pdf');
    });

    test('milestone: aggiunta e completamento con barra di avanzamento (f.16)', async ({ page }) => {
        const progetto = fixture<FixturaProgetto>('crea-progetto', { titolo: `Campagna ${Date.now()}` });

        await loginAdmin(page);
        await page.goto(`/progetti/${progetto.progetto_id}`);

        const cardMilestone = page.locator('.card', { hasText: 'Milestone' });
        await expect(cardMilestone.getByText('0% completato')).toBeVisible();

        // Aggiunge una milestone
        await cardMilestone.locator('input[name="titolo"]').fill('Definire programma');
        await cardMilestone.locator('button[type="submit"]').last().click();
        await expect(page.getByText('Definire programma')).toBeVisible();

        // La completa → avanzamento 100%
        await page.locator('form[action$="/toggle"] button').first().click();
        await expect(page.locator('.card', { hasText: 'Milestone' }).getByText('100% completato')).toBeVisible();
    });

    test('riunione: convocazione, votazione e bozza verbale (f.17/f.18)', async ({ page }) => {
        // Riunione svolta con un partecipante confermato (intestatario carica)
        const riunione = fixture<FixturaRiunione>('crea-riunione', {
            titolo: `Direttivo ${Date.now()}`,
            svolta: 1,
        });

        await loginAdmin(page);
        await page.goto(`/riunioni/${riunione.riunione_id}`);
        await expect(page.getByRole('heading', { name: new RegExp(riunione.titolo) })).toBeVisible();
        await expect(page.locator('h1 .badge', { hasText: 'Svolta' })).toBeVisible();

        // Registra l'esito di votazione sul primo punto dell'OdG
        await page.getByRole('button', { name: /Registra votazione/i }).first().click();
        const modalVot = page.locator('#votazioneModal');
        await expect(modalVot).toBeVisible();
        await modalVot.locator('#vot_esito').selectOption('approvato');
        await modalVot.locator('#vot_fav').fill('5');
        await modalVot.locator('#vot_con').fill('0');
        await modalVot.locator('#vot_ast').fill('1');
        await modalVot.getByRole('button', { name: 'Salva esito' }).click();

        await expect(page.locator('.alert', { hasText: 'Approvato' }).first()).toBeVisible();
        let stato = fixture<StatoRiunione>('stato-riunione', { riunione: riunione.riunione_id });
        expect(stato.votazioni).toBe(1);

        // Genera la bozza di verbale → redirect ai verbali con numero assegnato
        await page.getByRole('button', { name: /Genera bozza verbale/i }).click();
        await expect(page).toHaveURL(/verbali/);

        stato = fixture<StatoRiunione>('stato-riunione', { riunione: riunione.riunione_id });
        expect(stato.verbale_id).not.toBeNull();
        expect(stato.verbale_numero).toBeTruthy();
    });

    test('convocazione genera partecipanti dagli intestatari delle cariche (f.17)', async ({ page }) => {
        const riunione = fixture<FixturaRiunione>('crea-riunione', {
            titolo: `Convoca ${Date.now()}`,
            'con-carica': 1,
        });

        await loginAdmin(page);
        await page.goto(`/riunioni/${riunione.riunione_id}`);

        // Prima della convocazione non ci sono invitati
        await expect(page.getByText(/Nessun invitato/i)).toBeVisible();

        // Convoca → partecipante creato dalla carica attiva
        await page.locator('form[action$="/convoca"] button[type="submit"]').click();
        await expect(page.locator('.alert-success').first()).toContainText(/Convocazione inviata/i);

        const stato = fixture<StatoRiunione>('stato-riunione', { riunione: riunione.riunione_id });
        expect(stato.partecipanti).toBeGreaterThan(0);
    });

    test('pagina Decisioni aggrega le votazioni approvate (f.19)', async ({ page }) => {
        const riunione = fixture<FixturaRiunione>('crea-riunione', {
            titolo: `Delibera ${Date.now()}`,
            svolta: 1,
        });

        await loginAdmin(page);
        // Registra una votazione approvata via UI
        await page.goto(`/riunioni/${riunione.riunione_id}`);
        await page.getByRole('button', { name: /Registra votazione/i }).first().click();
        const modalVot = page.locator('#votazioneModal');
        await modalVot.locator('#vot_esito').selectOption('approvato');
        await modalVot.locator('#vot_fav').fill('7');
        await modalVot.getByRole('button', { name: 'Salva esito' }).click();
        await expect(page.locator('.alert', { hasText: 'Approvato' }).first()).toBeVisible();

        // La decisione approvata compare nell'archivio Decisioni
        await page.goto('/decisioni');
        await expect(page.getByRole('heading', { name: 'Decisioni' })).toBeVisible();
        const riga = page.locator('tr', { hasText: 'Approvazione bilancio' }).first();
        await expect(riga).toBeVisible();
        await expect(riga.getByText('Votazione')).toBeVisible();
    });

    test('adempimento ricorrente spuntato nello Scadenzario (f.20)', async ({ page }) => {
        const mese = new Date().getMonth() + 1;
        const adempimento = fixture<{ adempimento_id: string; titolo: string }>('seed-adempimenti', {
            titolo: `Assicurazione volontari ${Date.now()}`,
            mese,
        });

        await loginAdmin(page);
        await page.goto('/scadenzario');

        const card = page.locator('.card', { hasText: 'Adempimenti ricorrenti' });
        await expect(card).toBeVisible();
        const riga = card.locator('div', { hasText: adempimento.titolo }).last();
        await expect(card.getByText(adempimento.titolo)).toBeVisible();

        // Spunta l'adempimento come "fatto"
        await card.getByRole('button', { name: /Segna fatto/i }).first().click();
        await expect(page).toHaveURL(/scadenzario/);
        await expect(page.locator('.card', { hasText: 'Adempimenti ricorrenti' }).getByText(/Riapri/i).first()).toBeVisible();
        expect(riga).toBeTruthy();
    });
});
