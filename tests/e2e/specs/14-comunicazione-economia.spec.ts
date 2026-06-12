import { expect, test } from '@playwright/test';
import {
    accountVerificatoLoggato,
    azzeraThrottle,
    creaSocioConTessera,
    dataUtc,
    emailUnica,
    fixture,
    loginAdmin,
    nuovaSessione,
    SLUG,
} from '../helpers';

/**
 * Moduli comunicazione (f.7–f.12) ed economia (f.13–f.18) del piano
 * "Gestione 2": annunci sul mini-sito, sondaggi votati dagli account con
 * risultati admin, prima nota con movimenti automatici, donazioni pubbliche,
 * solleciti quote e scadenzario unificato.
 */
test.describe('Comunicazione ed economia (Gestione 2, sezioni B e C)', () => {
    test.beforeEach(() => azzeraThrottle());

    test('annuncio pubblicato dall\'admin visibile nella sezione Novità del mini-sito', async ({ page }) => {
        const titolo = `Annuncio E2E ${Date.now()}`;
        const corpo = `Contenuto dell'annuncio pubblicato dalla suite E2E (${Date.now()}).`;

        await loginAdmin(page);
        await page.goto('/annunci/nuovo');
        await expect(page.getByRole('heading', { name: 'Nuovo annuncio' })).toBeVisible();

        await page.locator('#titolo').fill(titolo);
        await page.locator('#corpo_html').fill(`<p>${corpo}</p>`);
        // Pubblicato da ieri: subito visibile sul mini-sito
        await page.locator('#pubblicato_dal').fill(`${dataUtc(-1)}T09:00`);
        await page.getByRole('button', { name: 'Crea annuncio' }).click();

        await expect(page.getByText('Annuncio creato con successo.')).toBeVisible();

        // Da ospite (nessuna sessione): la pagina pubblica Novità mostra l'annuncio
        await nuovaSessione(page);
        await page.goto(`/a/${SLUG}/novita`);
        await expect(page.getByRole('heading', { name: 'Novità' })).toBeVisible();
        await expect(page.getByRole('heading', { name: titolo })).toBeVisible();
        await expect(page.getByText(corpo)).toBeVisible();
    });

    test('sondaggio: voto dall\'area account con risultati immediati e riepilogo admin', async ({ page }) => {
        const domanda = `Sondaggio E2E ${Date.now()}: quale serata preferisci?`;

        // L'admin crea un sondaggio aperto (non solo-soci)
        await loginAdmin(page);
        await page.goto('/sondaggi/nuovo');
        await page.locator('#domanda').fill(domanda);
        await page.locator('#opzioni_testo').fill('Cinema\nGiochi da tavolo\nTrekking');
        await page.locator('#aperto_dal').fill(`${dataUtc(-1)}T09:00`);
        await page.getByRole('button', { name: 'Crea sondaggio' }).click();
        await expect(page.getByText('Sondaggio creato con successo.')).toBeVisible();
        const urlRisultati = page.url();

        // Un account verificato vota dal mini-sito
        await nuovaSessione(page);
        await accountVerificatoLoggato(page, 'sondaggio');
        await page.goto(`/a/${SLUG}/account/sondaggi`);

        const cardVoto = page.locator('.card', { hasText: domanda });
        await expect(cardVoto).toBeVisible();
        await cardVoto.getByLabel('Giochi da tavolo').check();
        await cardVoto.getByRole('button', { name: 'Vota' }).click();

        await expect(page.getByText('Grazie! Il tuo voto è stato registrato.')).toBeVisible();
        const cardEsito = page.locator('.card', { hasText: domanda });
        await expect(cardEsito.getByText('Hai votato:')).toBeVisible();
        await expect(cardEsito.getByText('Giochi da tavolo', { exact: false }).first()).toBeVisible();
        await expect(cardEsito.getByText('1 voto totale')).toBeVisible();

        // L'admin vede i risultati aggregati
        await nuovaSessione(page);
        await loginAdmin(page);
        await page.goto(urlRisultati);
        await expect(page.getByRole('heading', { name: 'Risultati sondaggio' })).toBeVisible();
        await expect(page.getByText(domanda)).toBeVisible();
        await expect(page.getByText('1 voto totale')).toBeVisible();
        await expect(page.locator('div.mb-3', { hasText: 'Giochi da tavolo' }).getByText('1 voto (100%)')).toBeVisible();
    });

    test('prima nota: la quota pagata genera il movimento automatico di entrata', async ({ page }) => {
        const socio = creaSocioConTessera(emailUnica('prima-nota'), { 'con-quota': 1 });
        // Il pagamento (fixture, come la registrazione manuale dell'admin)
        // genera il movimento automatico idempotente in prima nota
        const esito = fixture<{ ok?: boolean; movimento_id: string | null }>('paga-quota', { quota: socio.quota_id! });
        expect(esito.movimento_id).not.toBeNull();

        await loginAdmin(page);
        await page.goto('/prima-nota');
        await expect(page.getByRole('heading', { name: 'Prima nota' })).toBeVisible();

        const anno = new Date().getUTCFullYear();
        const riga = page.locator('tr', { hasText: `Quota ${anno} — E2E Socio` }).first();
        await expect(riga).toBeVisible();
        // Generato dal pagamento: badge "auto", categoria seedata e importo in entrata
        await expect(riga.locator('.badge', { hasText: 'auto' })).toBeVisible();
        await expect(riga.getByText('Quote associative')).toBeVisible();
        await expect(riga.getByText('Contanti')).toBeVisible();
        await expect(riga.getByText('25,00').first()).toBeVisible();
    });

    test('pagina pubblica Dona: form compilabile fino alla scelta del gateway', async ({ page }) => {
        fixture('configura-gateway'); // Stripe abilitato per il tenant e2e

        await page.goto(`/a/${SLUG}/dona`);
        await expect(page.getByRole('heading', { name: /Sostieni Associazione E2E/ })).toBeVisible();

        // Importo suggerito → campo precompilato
        await page.locator('.btn-importo', { hasText: '25' }).click();
        await expect(page.locator('#campoImporto')).toHaveValue('25');

        await page.locator('#campoNome').fill('Donatore E2E');
        await page.locator('#campoEmail').fill(emailUnica('donatore'));
        await page.locator('#campoAnonimo').check();

        // Scelta del gateway disponibile (il checkout reale non viene avviato)
        await expect(page.getByText('Scegli come donare *')).toBeVisible();
        await expect(page.getByRole('button', { name: /Dona con carta \(Stripe\)/ })).toBeVisible();
        await expect(page.getByText('Il pagamento avviene in modo sicuro')).toBeVisible();
    });

    test('sollecito quota: invio singolo tracciato con conteggio e data ultimo invio', async ({ page }) => {
        // Scadenza ravvicinata → la quota è in cima alla lista (ordinata per scadenza)
        const socio = creaSocioConTessera(emailUnica('sollecito'), { 'con-quota': 1, 'quota-scadenza': dataUtc(5) });

        await loginAdmin(page);
        await page.goto('/solleciti-quote');
        await expect(page.getByRole('heading', { name: 'Solleciti quote' })).toBeVisible();

        const bottoneSollecita = page.locator(`button[form="formSingolo${socio.quota_id}"]`);
        await expect(bottoneSollecita).toBeEnabled();

        page.once('dialog', (dialog) => dialog.accept());
        await bottoneSollecita.click();

        await expect(page.getByText('Sollecito inviato.')).toBeVisible();

        // Tracking per quota: conteggio 1 e data dell'ultimo sollecito
        const riga = page.locator('tr', { has: page.locator(`button[form="formSingolo${socio.quota_id}"]`) });
        await expect(riga.locator('.badge.bg-info', { hasText: '1' })).toBeVisible();
        await expect(riga.getByText(/\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}/)).toBeVisible();
    });

    test('scadenzario unificato: quota in scadenza elencata e filtro per tipo', async ({ page }) => {
        creaSocioConTessera(emailUnica('scadenzario'), { 'con-quota': 1, 'quota-scadenza': dataUtc(7) });
        const anno = new Date().getUTCFullYear();

        await loginAdmin(page);
        await page.goto('/scadenzario');
        await expect(page.getByRole('heading', { name: 'Scadenzario' })).toBeVisible();

        const riga = page.locator('tr', { hasText: `Quota ${anno} — E2E Socio` }).first();
        await expect(riga).toBeVisible();
        await expect(riga.locator('.badge', { hasText: 'Quota' })).toBeVisible();
        await expect(riga.getByText('25,00')).toBeVisible();

        // Filtro per tipo: con "documento" la voce quota sparisce
        await page.goto('/scadenzario?tipo=documento');
        await expect(page.locator('tr', { hasText: `Quota ${anno} — E2E Socio` })).toHaveCount(0);
    });
});
