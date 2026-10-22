import { writeFileSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { expect, test } from '@playwright/test';
import { SUPER_ADMIN, azzeraThrottle, fixture, loginAdmin, nuovaSessione } from '../helpers';

/**
 * Percorso completo nel browser: un'associazione si iscrive dal sito, conferma
 * l'email, il super admin approva, l'amministratore accede, importa i soci da
 * file e approva una pre-iscrizione arrivata dal modulo pubblico.
 */
test.describe('Percorso completo di una nuova associazione', () => {
    test.beforeEach(() => azzeraThrottle());

    test('iscrizione, approvazione, primo accesso, import soci e pre-iscrizione', async ({ page }) => {
        const suffisso = `${Date.now()}`.slice(-7);
        const nomeAssociazione = `Polisportiva Percorso ${suffisso}`;
        const adminEmail = `admin-percorso-${suffisso}@example.com`;
        const password = 'Lanterna-Velluto-Fiume-42';

        // 1. L'associazione trova il link nella pagina di accesso e si iscrive
        await page.goto('/login');
        await page.getByRole('link', { name: /iscrivila/i }).click();
        await expect(page.getByRole('heading', { name: 'Iscrivi la tua associazione' })).toBeVisible();
        await page.locator('#nome').fill(nomeAssociazione);
        await page.locator('#tipo').selectOption('ASD');
        await page.locator('#email').fill(`segreteria-${suffisso}@example.com`);
        await page.locator('#citta').fill('Bologna');
        await page.locator('#provincia').fill('BO');
        await page.locator('#admin_nome').fill('Marta');
        await page.locator('#admin_cognome').fill('Ferraresi');
        await page.locator('#admin_email').fill(adminEmail);
        await page.locator('#admin_password').fill(password);
        await page.locator('#privacy').check();
        await page.getByRole('button', { name: 'Invia la richiesta' }).click();
        await expect(page.getByRole('heading', { name: 'Controlla la tua email' })).toBeVisible();

        // 2. Conferma dal link ricevuto via email
        const { url } = fixture<{ url: string }>('link-verifica-registrazione', { email: adminEmail });
        await page.goto(url);
        await expect(page.getByRole('heading', { name: 'Email confermata' })).toBeVisible();

        // 3. Il super admin trova la richiesta e la approva
        await nuovaSessione(page);
        await loginAdmin(page, SUPER_ADMIN.email, SUPER_ADMIN.password);
        await page.goto('/richieste-registrazione');
        const riga = page.locator('tr', { hasText: nomeAssociazione });
        await expect(riga).toContainText(adminEmail);
        await riga.getByRole('button', { name: 'Approva' }).click();
        await expect(page.getByText(`${nomeAssociazione} è stata creata`)).toBeVisible();
        const stato = fixture<{ stato: string; associazione_id: string }>('stato-registrazione', { email: adminEmail });
        expect(stato.stato).toBe('approvata');

        // 4. L'amministratore accede con la password scelta all'iscrizione
        await nuovaSessione(page);
        await loginAdmin(page, adminEmail, password);
        await expect(page.locator('.tenant-name')).toContainText(nomeAssociazione);

        // 5. Importa i soci da file (CSV con intestazioni come il modello Excel)
        const cartella = mkdtempSync(join(tmpdir(), 'soci-'));
        const file = join(cartella, 'soci.csv');
        writeFileSync(file, [
            'nome,cognome,email,codice_fiscale,data_nascita',
            `Giulia,Bianchi${suffisso},giulia-${suffisso}@example.com,BNCGLI88C52A944I,1988-03-12`,
            `Luca,Ferri${suffisso},luca-${suffisso}@example.com,FRRLCU09A01A944P,2009-01-01`,
            `Senza,,senza-${suffisso}@example.com,,`,
        ].join('\n'));
        await page.goto('/soci/import');
        await page.locator('#file').setInputFiles(file);
        await page.getByRole('button', { name: 'Importa' }).click();
        await expect(page.getByText(/2 soci importati, 1 righe scartate/)).toBeVisible();

        // 6. Una persona si pre-iscrive dal modulo pubblico dell'associazione
        await nuovaSessione(page);
        await page.goto(`/preiscrizione/${stato.associazione_id}`);
        await expect(page.getByText(nomeAssociazione).first()).toBeVisible();
        await page.locator('#nome').fill('Elena');
        await page.locator('#cognome').fill(`Marchetti${suffisso}`);
        await page.locator('#email').fill(`elena-${suffisso}@example.com`);
        await page.locator('#data_nascita').fill('1995-06-15');
        await page.locator('#privacy_consenso').check();
        await page.getByRole('button', { name: /invia richiesta di pre-iscrizione/i }).click();
        await expect(page.getByText(/inviata/i).first()).toBeVisible();

        // 7. L'amministratore la trova, la approva e registra il pagamento
        await nuovaSessione(page);
        await loginAdmin(page, adminEmail, password);
        await page.goto('/soci?status=pending&tessera_stato=all');
        const pre = page.locator('#sociResults tr', { hasText: `Marchetti${suffisso}` });
        await pre.getByRole('button', { name: /approva/i }).click();
        await page.goto('/soci?tessera_stato=all');
        const daPagare = page.locator('#sociResults tr', { hasText: `Marchetti${suffisso}` });
        await expect(daPagare).toContainText('In Attesa di Pagamento');
        await daPagare.getByRole('button', { name: /conferma pagamento/i }).click();
        await page.locator('#cpImporto').fill('25');
        await page.locator('#cpMetodo').selectOption('contanti');
        await page.locator('#confermaPagamentoModal').getByRole('button', { name: 'Conferma' }).click();

        await page.goto('/soci?tessera_stato=all');
        await expect(page.locator('#sociResults')).toContainText(`Bianchi${suffisso}`);
        await expect(page.locator('#sociResults')).toContainText(`Ferri${suffisso}`);
        await expect(page.locator('#sociResults tr', { hasText: `Marchetti${suffisso}` })).toContainText('Attivo');
    });
});
