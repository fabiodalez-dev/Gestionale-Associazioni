import { expect, test } from '@playwright/test';
import {
    ADMIN,
    azzeraThrottle,
    creaEvento,
    creaSocioConTessera,
    emailUnica,
    fixture,
    loginAdmin,
    loginSocio,
    nuovaSessione,
    postJsonConCsrf,
    SOCIO_PASSWORD,
    SUPER_ADMIN,
} from '../helpers';

/**
 * Moduli membri (f.19–f.22) e piattaforma (f.23–f.25) del piano "Gestione 2":
 * richieste modifica dati approvate dall'admin, nuclei familiari, kiosk
 * check-in per evento, onboarding admin, cestino unificato e audit accessi.
 */
test.describe('Membri e piattaforma (Gestione 2, sezioni D ed E)', () => {
    test.beforeEach(() => azzeraThrottle());

    test('richiesta modifica dati: proposta dal socio, diff e approvazione admin', async ({ page }) => {
        const email = emailUnica('modifica-dati');
        const socio = creaSocioConTessera(email, { password: SOCIO_PASSWORD });
        const nuovoTelefono = `333${String(Date.now()).slice(-7)}`;

        // Il socio propone la modifica dall'area riservata
        await loginSocio(page, email);
        await page.goto('/area-soci/modifica-dati');
        await expect(page.getByRole('heading', { name: 'Richiedi modifica dati' })).toBeVisible();

        await page.locator('#md-telefono').fill(nuovoTelefono);
        await page.getByRole('button', { name: 'Invia richiesta' }).click();
        await expect(page.getByText(/Richiesta inviata/)).toBeVisible();

        // L'admin vede il diff campo per campo e approva
        await nuovaSessione(page);
        await loginAdmin(page);
        await page.goto('/richieste-modifica-dati');
        await expect(page.getByRole('heading', { name: 'Richieste modifica dati' })).toBeVisible();

        const card = page.locator('.card', { hasText: nuovoTelefono });
        await expect(card.getByText('Telefono')).toBeVisible();
        await expect(card.getByText('Area soci')).toBeVisible();

        page.once('dialog', (dialog) => dialog.accept());
        await card.getByRole('button', { name: 'Approva e applica' }).click();

        await expect(page.getByText('Richiesta approvata: le modifiche sono state applicate alla scheda del socio.')).toBeVisible();
        await expect(page.locator('tr', { hasText: nuovoTelefono }).locator('.badge', { hasText: 'Approvata' })).toBeVisible();

        // La scheda del socio riporta il telefono aggiornato
        await page.goto(`/soci/${socio.socio_id}`);
        await expect(page.locator('#tab-anagrafica dd', { hasText: nuovoTelefono })).toBeVisible();
    });

    test('nucleo familiare: creazione dalla scheda socio, aggiunta membro e vista per nuclei', async ({ page }) => {
        const tag = Date.now();
        const socioA = creaSocioConTessera(`nucleo-${tag}-a@example.com`);
        const socioB = creaSocioConTessera(`nucleo-${tag}-b@example.com`, { 'senza-tessera': 1 });
        const nomeNucleo = `Famiglia E2E ${tag}`;

        await loginAdmin(page);
        await page.goto(`/soci/${socioA.socio_id}`);

        // Creazione del nucleo con il socio A come capofamiglia
        const cardNucleo = page.locator('#nucleo-familiare');
        await expect(cardNucleo).toBeVisible();
        await cardNucleo.locator('#nuovo-nucleo-nome').fill(nomeNucleo);
        await cardNucleo.locator('form:has(#nuovo-nucleo-nome)').getByRole('button', { name: 'Crea' }).click();
        await expect(page.getByText(`Nucleo «${nomeNucleo}» creato.`)).toBeVisible();
        await expect(page.locator('#nucleo-familiare').getByText('Capofamiglia').first()).toBeVisible();

        // Aggiunta del socio B al nucleo
        await page.locator('#nucleo-aggiungi-socio').selectOption(socioB.socio_id);
        await page.locator('#nucleo-familiare').getByRole('button', { name: 'Aggiungi' }).first().click();
        await expect(page.getByText(`aggiunto al nucleo «${nomeNucleo}»`)).toBeVisible();

        // Vista raggruppata per nuclei: 2 membri e badge capofamiglia
        await page.goto(`/soci-nuclei?search=${encodeURIComponent(nomeNucleo)}`);
        const cardVista = page.locator('.card', { hasText: nomeNucleo });
        await expect(cardVista.getByText('2 membri')).toBeVisible();
        await expect(cardVista.locator('.badge', { hasText: 'Capofamiglia' })).toBeVisible();
    });

    test('kiosk check-in: pagina fullscreen e check-in tessera con contatore presenze', async ({ page }) => {
        const evento = creaEvento({ titolo: `Kiosk E2E ${Date.now()}` });
        const socio = creaSocioConTessera(emailUnica('kiosk'));

        await loginAdmin(page);
        await page.goto(`/checkin-kiosk/${evento.evento_id}`);

        // Layout kiosk dedicato: titolo evento, contatore a zero e hint scanner
        await expect(page.getByRole('heading', { name: evento.titolo })).toBeVisible();
        await expect(page.locator('#kioskCounter')).toHaveText('0');
        await expect(page.getByText('Inquadra il QR della tessera')).toBeVisible();
        await expect(page.getByText('Esci dal kiosk')).toBeVisible();

        // Check-in via endpoint (lo stesso chiamato dallo scanner del kiosk)
        const esito = await postJsonConCsrf(page, '/tessere/checkin', {
            evento_id: evento.evento_id,
            tessera_id: socio.tessera_id,
        });
        expect(esito.status).toBe(200);
        expect(esito.body.success).toBe(true);
        expect(esito.body.already_checked_in).toBe(false);

        // Il contatore live riflette la presenza registrata
        const contatore = await page.request.get(`/checkin-kiosk/${evento.evento_id}/contatore`, {
            headers: { Accept: 'application/json' },
        });
        expect(contatore.status()).toBe(200);
        expect(((await contatore.json()) as { presenze: number }).presenze).toBe(1);
    });

    test('onboarding admin: checklist in dashboard con progress e dismiss persistente', async ({ page }) => {
        await loginAdmin(page);
        await page.goto('/dashboard');

        const card = page.locator('#onboardingCard');
        await expect(card).toBeVisible();
        await expect(card.getByText('Per iniziare: configura Associazione E2E')).toBeVisible();
        await expect(card.getByText(/\d+ di \d+ passi completati/)).toBeVisible();
        // Passo mai completabile dalla suite → sempre presente con link "Vai"
        await expect(card.getByText("Carica il logo dell'associazione")).toBeVisible();
        await expect(card.getByRole('link', { name: /Vai/ }).first()).toBeVisible();

        // Dismiss persistente: la card sparisce
        await card.getByRole('button', { name: 'Nascondi' }).click();
        await expect(page.getByText('Checklist di configurazione nascosta.')).toBeVisible();
        await expect(page.locator('#onboardingCard')).toHaveCount(0);
    });

    test('cestino unificato: socio eliminato, elencato nel cestino e ripristinato', async ({ page }) => {
        const email = emailUnica('cestino');
        // Con tessera attiva: la lista soci filtra di default su tessera_stato=attive
        const socio = creaSocioConTessera(email);

        await loginAdmin(page);

        // Soft delete dalla lista soci (filtrata sull'email unica)
        await page.goto(`/soci?search=${encodeURIComponent(email)}`);
        const rigaSocio = page.locator('#sociResults tr', { hasText: email });
        page.once('dialog', (dialog) => dialog.accept());
        await rigaSocio.locator('button[title="Elimina"]').click();
        await expect(page.getByText('Socio spostato nel cestino. Puoi ripristinarlo dal cestino.')).toBeVisible();

        // Nel cestino (filtro per tipo) con etichetta e dettaglio email
        await page.goto('/cestino?tipo=soci');
        await expect(page.getByRole('heading', { name: 'Cestino' })).toBeVisible();
        const rigaCestino = page.locator('tr', { hasText: email });
        await expect(rigaCestino.getByText('Socio E2E')).toBeVisible();
        await expect(rigaCestino.locator('.badge', { hasText: 'Socio' })).toBeVisible();

        // Ripristino (il messaggio compare sia nel layout sia nella vista cestino)
        await rigaCestino.getByRole('button', { name: 'Ripristina' }).click();
        await expect(page.getByText('Socio ripristinato con successo.').first()).toBeVisible();

        // Il socio è di nuovo consultabile e attivo
        expect(fixture<{ stato: string }>('stato-socio', { email }).stato).toBe('Attivo');
        await page.goto(`/soci/${socio.socio_id}`);
        await expect(page.locator('#tab-anagrafica').getByText(email)).toBeVisible();
    });

    test('audit accessi: il login fallito viene registrato e consultato con i filtri', async ({ page }) => {
        // Tentativo di login admin con password sbagliata
        await page.goto('/login');
        await page.locator('#email').fill(ADMIN.email);
        await page.locator('#password').fill('PasswordSbagliata!2026');
        await page.locator('button.btn-login').click();
        await expect(page).not.toHaveURL(/dashboard/);

        // Sul tentativo fallito l'utente non è risolto → riga senza tenant:
        // la vede il super_admin in vista globale (per design del modulo)
        await loginAdmin(page, SUPER_ADMIN.email, SUPER_ADMIN.password);
        await page.goto(`/audit-accessi?esito=fallito&email=${encodeURIComponent(ADMIN.email)}`);
        await expect(page.getByRole('heading', { name: 'Audit accessi' })).toBeVisible();

        const rigaFallito = page.locator('tbody tr').first();
        await expect(rigaFallito.locator('.badge', { hasText: 'Fallito' })).toBeVisible();
        await expect(rigaFallito.getByText(ADMIN.email).first()).toBeVisible();
        await expect(rigaFallito.getByText('Admin', { exact: true })).toBeVisible();

        // Anche il login riuscito appena eseguito (super admin) è in audit
        await page.goto(`/audit-accessi?esito=login&email=${encodeURIComponent(SUPER_ADMIN.email)}`);
        await expect(page.locator('tbody tr').first().locator('.badge', { hasText: 'Login' })).toBeVisible();
    });
});
