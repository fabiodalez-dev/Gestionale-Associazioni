import { expect, test } from '@playwright/test';
import {
    accountVerificatoLoggato,
    azzeraThrottle,
    creaEvento,
    creaSocioConTessera,
    dataUtc,
    emailUnica,
    fixture,
    idPrenotazioneDaEsito,
    loginAccount,
    loginAdmin,
    nuovaSessione,
    prenota,
    SLUG,
    statoPrenotazioni,
} from '../helpers';

/** Mese (YYYY-MM) dell'evento creato dalla fixture: data_evento = now+10 giorni. */
const MESE_EVENTO = dataUtc(10).slice(0, 7);

/**
 * Modulo eventi-vita (f.7–f.12): calendari admin e pubblico con export
 * ICS, serie ricorrenti, eventi riservati ai soci e feedback post-evento
 * dal link firmato dell'email.
 */
test.describe('Vita sociale: calendari, serie, solo-soci e feedback', () => {
    test.beforeEach(() => azzeraThrottle());

    test('calendario admin: evento del mese in griglia ed export ICS del singolo evento', async ({ page }) => {
        const evento = creaEvento({ titolo: `Calendario admin ${Date.now()}`, capienza: 10 });

        await loginAdmin(page);
        await page.goto(`/calendario-eventi?mese=${MESE_EVENTO}`);

        await expect(page.getByRole('heading', { name: 'Calendario eventi' })).toBeVisible();
        await expect(page.getByText(evento.titolo)).toBeVisible();

        const ics = await page.request.get(`/eventi/${evento.evento_id}/evento.ics`);
        expect(ics.status()).toBe(200);
        expect(ics.headers()['content-type']).toContain('text/calendar');
        const corpo = await ics.text();
        expect(corpo).toContain('BEGIN:VCALENDAR');
        expect(corpo).toContain(evento.titolo);
    });

    test('calendario pubblico del mini-sito con feed ICS e .ics del singolo evento', async ({ page }) => {
        const evento = creaEvento({ titolo: `Calendario pubblico ${Date.now()}`, capienza: 10 });

        await page.goto(`/a/${SLUG}/calendario?mese=${MESE_EVENTO}`);
        await expect(page.getByText(evento.titolo)).toBeVisible();

        const feed = await page.request.get(`/a/${SLUG}/calendario.ics`);
        expect(feed.status()).toBe(200);
        expect(feed.headers()['content-type']).toContain('text/calendar');
        expect(await feed.text()).toContain(evento.titolo);

        const singolo = await page.request.get(`/a/${SLUG}/eventi/${evento.evento_id}/evento.ics`);
        expect(singolo.status()).toBe(200);
        expect(singolo.headers()['content-type']).toContain('text/calendar');
        expect(await singolo.text()).toContain('BEGIN:VEVENT');
    });

    test('evento ricorrente settimanale: la serie genera tutte le occorrenze', async ({ page }) => {
        const titolo = `Corso settimanale ${Date.now()}`;

        await loginAdmin(page);
        await page.goto('/eventi-ricorrenti/nuovo');
        await expect(page.getByRole('heading', { name: 'Nuovo evento ricorrente' })).toBeVisible();

        await page.locator('#titolo').fill(titolo);
        await page.locator('#data_evento').fill(`${dataUtc(2)}T18:00`);
        await page.locator('#luogo').fill('Sala corsi E2E');
        await page.locator('#frequenza').selectOption('settimanale');
        // Primo evento a +2 giorni, fino a +23: occorrenze a +2, +9, +16, +23 → 4
        await page.locator('#fino_al').fill(dataUtc(23));
        await page.locator('#stato_pubblicazione').selectOption('pubblicato');
        await page.getByRole('button', { name: /crea la serie|salva/i }).click();

        await expect(page.getByText(/Serie ricorrente creata: 4 eventi/)).toBeVisible();

        const serie = fixture<{ occorrenze: number; serie_id: string | null; primo_evento_id: string }>(
            'conta-serie',
            { titolo },
        );
        expect(serie.occorrenze).toBe(4);
        expect(serie.serie_id).not.toBeNull();

        // La pagina "Serie" elenca le occorrenze generate
        await page.goto(`/eventi/${serie.primo_evento_id}/serie`);
        await expect(page.getByRole('heading', { name: `Serie: ${titolo}` })).toBeVisible();
    });

    test('evento solo-soci: ospite e account non collegato bloccati, account socio prenota', async ({ page }) => {
        const evento = creaEvento({ titolo: `Solo soci ${Date.now()}`, capienza: 10, 'solo-soci': 1 });

        // Ospite non autenticato: messaggio dedicato, niente form di prenotazione
        await page.goto(`/a/${SLUG}/eventi/${evento.evento_id}`);
        await expect(page.getByText(/riservato ai soci/i).first()).toBeVisible();
        await expect(page.getByRole('button', { name: /^prenota/i })).toHaveCount(0);

        // Account verificato ma NON collegato a un socio: CTA "Diventa socio"
        await accountVerificatoLoggato(page, 'solosoci-estraneo');
        await page.goto(`/a/${SLUG}/eventi/${evento.evento_id}`);
        await expect(page.getByText('Evento riservato ai soci.')).toBeVisible();
        await expect(page.getByRole('button', { name: /^prenota/i })).toHaveCount(0);

        // Account collegato a un socio attivo: la prenotazione va a buon fine
        await nuovaSessione(page);
        const socio = creaSocioConTessera(emailUnica('solosoci-socio'));
        const emailAccount = emailUnica('solosoci-account');
        fixture('crea-account', { email: emailAccount });
        fixture('collega-account-socio', { email: emailAccount, socio: socio.socio_id });

        await loginAccount(page, emailAccount);
        await prenota(page, evento.evento_id);

        await expect(page).toHaveURL(/\/prenotazioni\/[^/]+\/esito/);
        expect(idPrenotazioneDaEsito(page)).toBeTruthy();
        const prenotazioni = statoPrenotazioni(emailAccount);
        expect(prenotazioni).toHaveLength(1);
        expect(prenotazioni[0].stato).toBe('confermata');
    });

    test('feedback post-evento: link firmato, compilazione, blocco ricompilazione e firma obbligatoria', async ({ page }) => {
        const evento = creaEvento({ titolo: `Feedback ${Date.now()}`, capienza: 10 });
        const email = emailUnica('feedback');
        fixture('crea-account', { email });

        const { url } = fixture<{ url: string; token: string }>('link-feedback', {
            email,
            evento: evento.evento_id,
        });

        // Il link firmato dell'email apre il form con i dati dell'evento
        await page.goto(url);
        await expect(page.getByRole('heading', { name: evento.titolo })).toBeVisible();

        await page.locator('label[for="voto-5"]').click();
        await page.locator('#commento').fill('Bellissima serata, organizzazione impeccabile (test E2E).');
        await page.getByRole('button', { name: 'Invia il feedback' }).click();

        await expect(page.getByText('Grazie! Il tuo feedback è stato registrato.')).toBeVisible();

        // Un solo feedback per prenotazione: alla riapertura il form non c'è più
        await page.goto(url);
        await expect(page.getByText('Hai già inviato il feedback per questo evento. Grazie!')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Invia il feedback' })).toHaveCount(0);

        // Senza la firma il link non è valido (middleware signed → 403)
        const nonFirmato = await page.request.get(url.split('?')[0]);
        expect(nonFirmato.status()).toBe(403);
    });

    test('report feedback admin: media e commento del partecipante', async ({ page }) => {
        const evento = creaEvento({ titolo: `Report feedback ${Date.now()}`, capienza: 10 });
        const email = emailUnica('report-feedback');
        fixture('crea-account', { email });
        const { url } = fixture<{ url: string }>('link-feedback', { email, evento: evento.evento_id });

        await page.goto(url);
        await page.locator('label[for="voto-4"]').click();
        await page.locator('#commento').fill('Molto bello, torneremo (report E2E).');
        await page.getByRole('button', { name: 'Invia il feedback' }).click();
        await expect(page.getByText('Grazie! Il tuo feedback è stato registrato.')).toBeVisible();

        await loginAdmin(page);
        await page.goto(`/eventi/${evento.evento_id}/feedback`);
        await expect(page.getByText('Molto bello, torneremo (report E2E).')).toBeVisible();
    });
});
