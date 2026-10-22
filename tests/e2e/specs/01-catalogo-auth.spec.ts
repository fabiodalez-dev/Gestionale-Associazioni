import { expect, test } from '@playwright/test';
import {
    SLUG,
    azzeraThrottle,
    creaEvento,
    emailUnica,
    fixture,
    loginAccount,
    prenota,
    registraAccount,
} from '../helpers';

test.describe('Catalogo pubblico e autenticazione account', () => {
    test.beforeEach(() => azzeraThrottle());

    test('catalogo: brand, badge Gratuito, prezzo e posti rimasti', async ({ page }) => {
        const suffisso = Date.now();
        const gratuito = creaEvento({ titolo: `Sagra gratuita ${suffisso}`, capienza: 5 });
        const aPagamento = creaEvento({ titolo: `Concerto a pagamento ${suffisso}`, capienza: 10, prezzo: 12.5 });

        await page.goto(`/a/${SLUG}/eventi`);

        // Brand dell'associazione nella navbar
        await expect(page.locator('.navbar-brand')).toContainText('Associazione E2E');

        // Evento gratuito: badge "Gratuito" e posti rimasti
        const cardGratuito = page.locator('.evento-card', { hasText: gratuito.titolo });
        await expect(cardGratuito.locator('.badge', { hasText: 'Gratuito' })).toBeVisible();
        await expect(cardGratuito.getByText('5 posti rimasti')).toBeVisible();

        // Evento a pagamento: prezzo formattato e posti rimasti
        const cardPagamento = page.locator('.evento-card', { hasText: aPagamento.titolo });
        await expect(cardPagamento.getByText('€ 12,50')).toBeVisible();
        await expect(cardPagamento.getByText('10 posti rimasti')).toBeVisible();
    });

    test('slug inesistente e frontend disattivato rispondono 404', async ({ page }) => {
        const inesistente = await page.goto('/a/slug-che-non-esiste/eventi');
        expect(inesistente?.status()).toBe(404);

        // 'spenta' esiste ma ha il frontend pubblico disattivato
        const spenta = await page.goto('/a/spenta/eventi');
        expect(spenta?.status()).toBe(404);
    });

    test('registrazione felice → avviso di verifica email', async ({ page }) => {
        const email = emailUnica('registrazione');

        await registraAccount(page, { email });

        await expect(page).toHaveURL(new RegExp(`/a/${SLUG}/verifica-email$`));
        await expect(page.getByRole('heading', { name: /verifica il tuo indirizzo email/i })).toBeVisible();
        await expect(page.getByText(/registrazione completata/i)).toBeVisible();
        await expect(page.getByText(email)).toBeVisible();
    });

    test('registrazione: password debole e privacy non accettata → errori di validazione', async ({ page }) => {
        await page.goto(`/a/${SLUG}/registrati`);
        await page.locator('#nome').fill('Test');
        await page.locator('#cognome').fill('Validazione');
        await page.locator('#email').fill(emailUnica('validazione'));
        await page.locator('#password').fill('corta1'); // < 10 caratteri
        await page.locator('#password_confirmation').fill('corta1');
        // Privacy volutamente NON accettata (il form ha novalidate)
        await page.getByRole('button', { name: /registrati/i }).click();

        await expect(page.locator('#password')).toHaveClass(/is-invalid/);
        await expect(page.locator('[data-formello-field="password"] .invalid-feedback')).toContainText(/10 caratteri/i);
        await expect(
            page.getByText("Devi accettare l'informativa sulla privacy per registrarti.").first(),
        ).toBeVisible();
    });

    test('account non verificato non prenota; dopo la verifica la prenotazione riesce', async ({ page }) => {
        const evento = creaEvento({ titolo: `Solo verificati ${Date.now()}`, capienza: 5 });
        const email = emailUnica('non-verificato');

        await registraAccount(page, { email });
        await expect(page).toHaveURL(/verifica-email$/);

        // Tentativo di prenotare da non verificato: il middleware riporta all'avviso
        await prenota(page, evento.evento_id);
        await expect(page).toHaveURL(/verifica-email$/);
        await expect(page.getByRole('heading', { name: /verifica il tuo indirizzo email/i })).toBeVisible();

        // Dopo la verifica via fixture la stessa prenotazione va a buon fine
        fixture('verifica-account', { email });
        await prenota(page, evento.evento_id);
        await expect(page.getByText(/prenotazione confermata/i)).toBeVisible();
    });

    test('login errato → errore; login corretto → nome in navbar; logout → ospite', async ({ page }) => {
        const email = emailUnica('login');
        fixture('crea-account', { email });

        // Password sbagliata: errore generico, si resta sul login
        await page.goto(`/a/${SLUG}/login`);
        await page.locator('#email').fill(email);
        await page.locator('#password').fill('PasswordSbagliata1');
        await page.getByRole('button', { name: /accedi/i }).click();
        await expect(page.getByText('Credenziali non valide.').first()).toBeVisible();
        await expect(page).toHaveURL(new RegExp(`/a/${SLUG}/login$`));

        // Login corretto: la navbar mostra il nome dell'account
        await loginAccount(page, email);
        const menuUtente = page.getByRole('button', { name: /account e2e/i });
        await expect(menuUtente).toBeVisible();

        // Logout dal menu: si torna ospiti (link Accedi di nuovo visibile)
        await menuUtente.click();
        await page.getByRole('button', { name: /esci/i }).click();
        await expect(page.getByRole('link', { name: 'Accedi', exact: true })).toBeVisible();
    });

    test('verifica email dal link firmato → area riservata raggiungibile', async ({ page }) => {
        const email = emailUnica('verifica-link');

        await registraAccount(page, { email });
        await expect(page).toHaveURL(/verifica-email$/);

        const { url } = fixture<{ url: string }>('link-verifica', { email });
        await page.goto(url);
        await expect(page.getByText('Email verificata con successo!')).toBeVisible();

        // Da verificato l'area riservata è raggiungibile
        await page.goto(`/a/${SLUG}/account`);
        await expect(page.getByRole('heading', { name: /ciao/i })).toBeVisible();
    });
});
