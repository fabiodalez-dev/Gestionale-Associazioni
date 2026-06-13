import { expect, test } from '@playwright/test';
import {
    azzeraThrottle,
    creaSocioConTessera,
    emailUnica,
    fixture,
    loginAdmin,
    loginSocio,
    SOCIO_PASSWORD,
} from '../helpers';

/**
 * Round 3 "Organizzazione" — sezioni A (Volontari & turni) e B (Spazi & risorse).
 *
 * Copre i flussi end-to-end:
 *  - turno aperto creato dall'admin → auto-candidatura del socio dall'area
 *    riservata → conferma dell'admin (f.3, f.4);
 *  - registro ore al completamento del turno + attestato PDF (f.5);
 *  - prenotazione spazio con conflitto di overlap rifiutato (f.8);
 *  - prestito attrezzatura con disponibilità esaurita bloccato (f.10);
 *  - evento con spazio e warning conflitto non bloccante (f.11).
 */

type FixturaTurno = { turno_id: string; titolo: string };
type FixturaSpazio = { spazio_id: string; nome: string };
type FixturaPrenotazione = {
    prenotazione_id: string;
    titolo: string;
    overlap_dal: string;
    overlap_al: string;
};
type StatoTurno = {
    assegnazioni: Array<{ socio_email: string | null; stato: string; ore_effettive: number | null }>;
};

test.describe('Volontari, turni e spazi (Organizzazione 3 — A/B)', () => {
    test.beforeEach(() => azzeraThrottle());

    test('turno aperto: auto-candidatura del socio e conferma dell admin (f.3/f.4)', async ({ page }) => {
        const email = emailUnica('volontario');
        const socio = creaSocioConTessera(email, { password: SOCIO_PASSWORD });
        fixture('marca-volontario', { email });
        const turno = fixture<FixturaTurno>('crea-turno', { titolo: `Mercatino ${Date.now()}`, posti: 2 });

        // Il socio si auto-candida dall'area riservata
        await loginSocio(page, email);
        await page.goto('/area-soci/volontariato');
        const cardAperti = page.locator('.card', { hasText: 'Turni aperti' });
        const rigaTurno = cardAperti.locator('li.list-group-item', { hasText: turno.titolo });
        await expect(rigaTurno).toBeVisible();
        await rigaTurno.getByRole('button', { name: /Mi offro/i }).click();

        // Candidatura inviata (non auto-conferma): risulta "In attesa"
        await expect(page).toHaveURL(/area-soci\/volontariato/);
        const cardMiei = page.locator('.card', { hasText: 'I miei turni' });
        await expect(cardMiei.getByText(turno.titolo)).toBeVisible();
        await expect(cardMiei.getByText('In attesa')).toBeVisible();

        let stato = fixture<StatoTurno>('stato-turno', { turno: turno.turno_id });
        expect(stato.assegnazioni).toHaveLength(1);
        expect(stato.assegnazioni[0]).toMatchObject({ socio_email: email, stato: 'proposto' });

        // L'admin conferma la candidatura dalla pagina del turno
        await page.context().clearCookies();
        await loginAdmin(page);
        await page.goto(`/turni/${turno.turno_id}`);
        await expect(page.getByText('Proposto')).toBeVisible();
        await page.locator(`form[action$="/conferma"] button`).first().click();

        await expect(page).toHaveURL(new RegExp(`/turni/${turno.turno_id}`));
        stato = fixture<StatoTurno>('stato-turno', { turno: turno.turno_id });
        expect(stato.assegnazioni[0].stato).toBe('confermato');
        expect(socio.socio_id).toBeTruthy();
    });

    test('registro ore al completamento del turno e attestato PDF (f.5)', async ({ page }) => {
        const email = emailUnica('ore');
        const socio = creaSocioConTessera(email, { password: SOCIO_PASSWORD });
        fixture('marca-volontario', { email });
        const turno = fixture<FixturaTurno>('crea-turno', {
            titolo: `Allestimento ${Date.now()}`,
            posti: 1,
            'auto-conferma': 1,
        });

        // Auto-candidatura con auto-conferma → assegnazione confermata subito
        await loginSocio(page, email);
        await page.goto('/area-soci/volontariato');
        const rigaTurno = page
            .locator('.card', { hasText: 'Turni aperti' })
            .locator('li.list-group-item', { hasText: turno.titolo });
        await rigaTurno.getByRole('button', { name: /Mi offro/i }).click();
        await expect(
            page.locator('.card', { hasText: 'I miei turni' }).locator('li.list-group-item', { hasText: turno.titolo }).getByText('Confermato'),
        ).toBeVisible();

        // L'admin registra le presenze (ore effettive precompilate dalla durata)
        await page.context().clearCookies();
        await loginAdmin(page);
        await page.goto(`/turni/${turno.turno_id}`);
        await page.getByRole('button', { name: /Registra presenze e chiudi/i }).click();

        const stato = fixture<StatoTurno>('stato-turno', { turno: turno.turno_id });
        expect(stato.assegnazioni[0].ore_effettive).not.toBeNull();
        expect(Number(stato.assegnazioni[0].ore_effettive)).toBeGreaterThan(0);

        // Attestato PDF annuale dalle ore registrate: 200 application/pdf
        const anno = new Date().getFullYear();
        const risposta = await page.request.get(`/soci/${socio.socio_id}/attestato-volontariato?anno=${anno}`);
        expect(risposta.status()).toBe(200);
        expect(risposta.headers()['content-type']).toContain('application/pdf');
        expect((await risposta.body()).length).toBeGreaterThan(0);
    });

    test('prenotazione spazio: conflitto di overlap rifiutato (f.8)', async ({ page }) => {
        const spazio = fixture<FixturaSpazio>('crea-spazio', { titolo: `Sala A ${Date.now()}` });
        const esistente = fixture<FixturaPrenotazione>('crea-prenotazione-spazio', { spazio: spazio.spazio_id });

        await loginAdmin(page);
        await page.goto(`/spazi/prenotazioni?spazio=${spazio.spazio_id}`);

        // La prenotazione esistente compare nella lista
        await expect(page.getByText(esistente.titolo).first()).toBeVisible();

        // Nuova prenotazione che si sovrappone all'esistente → bloccata
        await page.getByRole('button', { name: /Nuova prenotazione/i }).click();
        const modal = page.locator('#prenotazioneModal');
        await expect(modal).toBeVisible();
        await modal.locator('#prenotazione_spazio_id').selectOption(spazio.spazio_id);
        await modal.locator('#prenotazione_titolo').fill('Riunione in conflitto');
        await modal.locator('#prenotazione_dal').fill(esistente.overlap_dal);
        await modal.locator('#prenotazione_al').fill(esistente.overlap_al);
        await modal.getByRole('button', { name: 'Salva' }).click();

        // Errore di conflitto con indicazione della prenotazione esistente
        // (mostrato sia nell'alert riepilogativo sia nel feedback del campo)
        await expect(page.getByText(/già prenotato/i).first()).toBeVisible();
        await expect(page.getByText(new RegExp(esistente.titolo)).first()).toBeVisible();
    });

    test('prestito attrezzatura: disponibilità esaurita blocca la registrazione (f.10)', async ({ page }) => {
        const emailPrest = emailUnica('prestito');
        const socio = creaSocioConTessera(emailPrest);
        // Attrezzatura con 1 pezzo già tutto in prestito → 0 disponibili
        const attrezzatura = fixture<{ attrezzatura_id: string; nome: string; disponibile: number }>(
            'crea-attrezzatura',
            { titolo: `Proiettore ${Date.now()}`, quantita: 1, 'tutto-in-prestito': 1, socio: socio.socio_id },
        );
        expect(attrezzatura.disponibile).toBe(0);

        await loginAdmin(page);
        await page.goto('/prestiti');

        // Nel form l'attrezzatura esaurita compare con "(0 disponibili)" ed è disabilitata
        await page.getByRole('button', { name: /Nuovo prestito/i }).click();
        const modal = page.locator('#prestitoModal');
        await expect(modal).toBeVisible();
        const opzione = modal.locator(`#prestito_attrezzatura_id option[value="${attrezzatura.attrezzatura_id}"]`);
        await expect(opzione).toHaveText(/0 disponibili/);
        await expect(opzione).toBeDisabled();

        // Forziamo comunque l'invio (bypass del disabled lato server): deve bloccare
        const csrf = await page.locator('#prestitoModal input[name="_token"]').inputValue();
        const risposta = await page.request.post('/prestiti', {
            form: {
                _token: csrf,
                attrezzatura_id: attrezzatura.attrezzatura_id,
                socio_id: socio.socio_id,
                quantita: '1',
                dal: new Date().toISOString().slice(0, 10),
                restituzione_prevista: new Date(Date.now() + 7 * 86_400_000).toISOString().slice(0, 10),
            },
        });
        // Validazione fallita → redirect indietro con errori in sessione
        const body = await risposta.text();
        expect(body).toMatch(/Disponibilità insufficiente/i);
    });

    test('evento con spazio: warning conflitto non bloccante al salvataggio (f.11)', async ({ page }) => {
        const spazio = fixture<FixturaSpazio>('crea-spazio', { titolo: `Auditorium ${Date.now()}` });
        // Prenotazione esistente domani 10:00–12:00 sullo stesso spazio
        fixture<FixturaPrenotazione>('crea-prenotazione-spazio', { spazio: spazio.spazio_id });

        await loginAdmin(page);
        await page.goto('/eventi');
        await page.locator('#btnNuovoEvento').click();
        const modal = page.locator('#eventoModal');
        await expect(modal).toBeVisible();

        await modal.locator('#titolo').fill(`Concerto ${Date.now()}`);
        // Stesso giorno della prenotazione, orario in sovrapposizione (11:00)
        const domani = new Date(Date.now() + 86_400_000).toISOString().slice(0, 10);
        await modal.locator('#data_evento').fill(`${domani}T11:00`);
        await modal.locator('#spazio_id').selectOption(spazio.spazio_id);
        await modal.locator('#titolo').scrollIntoViewIfNeeded();
        await modal.getByRole('button', { name: /Salva|Crea/i }).first().click();

        // L'evento viene comunque salvato, ma con avviso di conflitto (warning)
        await expect(page.locator('.alert-warning')).toContainText(/spazio scelto risulta già occupato/i);
    });
});
