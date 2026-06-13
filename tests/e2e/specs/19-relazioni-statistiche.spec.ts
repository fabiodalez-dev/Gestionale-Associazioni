import { expect, test } from '@playwright/test';
import {
    accountVerificatoLoggato,
    azzeraThrottle,
    configuraFormIscrizione,
    creaSocioConTessera,
    emailUnica,
    fixture,
    loginAdmin,
    SLUG,
    statoRichiesta,
} from '../helpers';

/**
 * Round 3 "Organizzazione" — sezione E (Relazioni & crescita).
 *
 * Copre i flussi end-to-end:
 *  - contatto esterno (mini-CRM) creato e filtrato per tipo (f.21);
 *  - sponsor visibile in "I nostri sostenitori" sulla home pubblica (f.22);
 *  - referral: link /iscriviti?ref=CODICE → codice salvato sulla richiesta (f.23);
 *  - galleria foto pubblica dell'evento con immagine (f.24);
 *  - statistiche annuali con export PDF (f.25).
 */

test.describe('Relazioni e statistiche (Organizzazione 3 — E)', () => {
    test.beforeEach(() => {
        azzeraThrottle();
        configuraFormIscrizione();
    });

    test('contatto esterno creato e filtrabile per tipo (f.21)', async ({ page }) => {
        const nome = `Tipografia ${Date.now()}`;

        await loginAdmin(page);
        await page.goto('/contatti-esterni');
        await expect(page.getByRole('heading', { name: 'Contatti esterni' })).toBeVisible();

        // Crea un contatto di tipo "fornitore"
        await page.getByRole('button', { name: /Nuovo contatto/i }).click();
        const form = page.locator('#nuovoContatto');
        await form.locator('#nome-new').fill(nome);
        await form.locator('#tipo-new').selectOption('fornitore');
        await form.locator('#referente-new').fill('Mario Rossi');
        await form.getByRole('button', { name: /Crea contatto/i }).click();

        await expect(page.getByText('Contatto creato.').first()).toBeVisible();
        await expect(page.getByText(nome)).toBeVisible();

        // Filtro per tipo "ente" → il fornitore non compare
        await page.locator('#tipo').selectOption('ente');
        await expect(page.getByText(nome)).toHaveCount(0);

        // Filtro per tipo "fornitore" → ricompare
        await page.locator('#tipo').selectOption('fornitore');
        await expect(page.getByText(nome)).toBeVisible();
    });

    test('sponsor visibile in "I nostri sostenitori" sulla home pubblica (f.22)', async ({ page }) => {
        const nomeSponsor = `Banca ${Date.now()}`;
        fixture('crea-sponsor', { titolo: nomeSponsor, livello: 'oro', visibile: 1 });

        await page.goto(`/a/${SLUG}`);

        const sezione = page.locator('section', { hasText: 'I nostri sostenitori' });
        await expect(sezione.getByRole('heading', { name: 'I nostri sostenitori' })).toBeVisible();
        await expect(sezione.getByText(nomeSponsor)).toBeVisible();
        await expect(sezione.getByText('Oro')).toBeVisible();
    });

    test('referral: il link ?ref=CODICE salva il codice sulla richiesta (f.23)', async ({ page }) => {
        // Socio "ambasciatore" con il suo codice referral
        const emailReferrer = emailUnica('referrer');
        const referrer = creaSocioConTessera(emailReferrer);
        const ref = fixture<{ codice_referral: string }>('referral-socio', { socio: referrer.socio_id });
        expect(ref.codice_referral).toBeTruthy();

        // Un nuovo account arriva dal link di invito e invia la richiesta
        const emailNuovo = await accountVerificatoLoggato(page, 'invitato');
        await page.goto(`/a/${SLUG}/iscriviti?ref=${ref.codice_referral}`);
        await expect(page.getByRole('heading', { name: /iscriviti a/i })).toBeVisible();
        // Il codice è iniettato come hidden field dal composer referral
        await expect(page.locator('input[name="referral_codice"]')).toHaveValue(ref.codice_referral);

        // Compila i campi standard obbligatori (telefono + data di nascita)
        await page.locator('#campo-telefono').fill('+39 333 1234567');
        await page.locator('#campo-data_nascita').fill('1990-05-10');
        await page.getByRole('button', { name: /invia la richiesta di iscrizione/i }).click();

        // La richiesta è stata creata col referral salvato
        const richiesta = statoRichiesta(emailNuovo);
        expect(richiesta.stato).toBe('in_attesa');
        expect(richiesta.referral_codice).toBe(ref.codice_referral);
    });

    test('galleria foto pubblica dell evento con immagine (f.24)', async ({ page }) => {
        const evento = fixture<{ evento_id: string; titolo: string; foto: number }>('crea-evento-galleria', {
            titolo: `Festa estate ${Date.now()}`,
        });
        expect(evento.foto).toBe(1);

        await page.goto(`/a/${SLUG}/eventi/${evento.evento_id}`);
        await expect(page.getByRole('heading', { name: new RegExp(evento.titolo) }).first()).toBeVisible();

        const galleria = page.locator('section', { hasText: 'Galleria foto' });
        await expect(galleria.getByRole('heading', { name: /Galleria foto/i })).toBeVisible();
        const img = galleria.locator('img').first();
        await expect(img).toBeVisible();

        // L'immagine è realmente servita (200)
        const src = await img.getAttribute('src');
        expect(src).toBeTruthy();
        const risposta = await page.request.get(src as string);
        expect(risposta.status()).toBe(200);
        expect(risposta.headers()['content-type']).toContain('image/');
    });

    test('statistiche annuali con export PDF (f.25)', async ({ page }) => {
        const anno = new Date().getFullYear();

        await loginAdmin(page);
        await page.goto('/statistiche-annuali');
        await expect(page.getByRole('heading', { name: 'Statistiche annuali' })).toBeVisible();
        await expect(page.getByText('Nuovi soci')).toBeVisible();
        await expect(page.getByRole('link', { name: /Export PDF/i })).toBeVisible();

        // Export PDF "per l'assemblea": 200 application/pdf
        const risposta = await page.request.get(`/statistiche-annuali/pdf?anno=${anno}`);
        expect(risposta.status()).toBe(200);
        expect(risposta.headers()['content-type']).toContain('application/pdf');
        expect((await risposta.body()).length).toBeGreaterThan(0);
    });
});
