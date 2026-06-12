import { execFileSync } from 'node:child_process';
import { expect, type Page } from '@playwright/test';

const PROJECT_ROOT = new URL('../..', import.meta.url).pathname;

/** Variabili identiche a serve.sh: i comandi artisan operano sullo stesso DB e2e. */
const E2E_ENV = {
    ...process.env,
    APP_ENV: 'local',
    APP_URL: 'http://127.0.0.1:8011',
    DB_DATABASE: 'associazione_soci_e2e',
    MAIL_MAILER: 'log',
    QUEUE_CONNECTION: 'sync',
    CACHE_STORE: 'file',
    SESSION_DRIVER: 'file',
    E2E_FIXTURES: '1',
};

/** Esegue un comando artisan nel contesto e2e e ritorna stdout. */
export function artisan(args: string[]): string {
    return execFileSync('php', ['artisan', ...args], {
        cwd: PROJECT_ROOT,
        env: E2E_ENV,
        encoding: 'utf8',
    }).trim();
}

/** Esegue e2e:fixture e ritorna il JSON decodificato. */
export function fixture<T = Record<string, unknown>>(azione: string, opzioni: Record<string, string | number> = {}): T {
    const args = ['e2e:fixture', azione];
    for (const [chiave, valore] of Object.entries(opzioni)) {
        args.push(`--${chiave}=${valore}`);
    }
    const out = artisan(args);
    const ultimaRiga = out.split('\n').filter(Boolean).pop() ?? '{}';
    return JSON.parse(ultimaRiga) as T;
}

export const SLUG = 'e2e';
export const ACCOUNT_PASSWORD = 'AccountE2e2026';
export const ADMIN = { email: 'admin-e2e@example.com', password: 'AdminE2e!2026' };
export const SUPER_ADMIN = { email: 'super-e2e@example.com', password: 'SuperE2e!2026' };

/**
 * Data a N giorni da oggi in formato YYYY-MM-DD, calcolata in UTC
 * (coerente con il timezone dell'app Laravel: config('app.timezone')=UTC).
 */
export function dataUtc(giorniAvanti: number): string {
    return new Date(Date.now() + giorniAvanti * 86_400_000).toISOString().slice(0, 10);
}

/** Email unica per run (evita collisioni tra esecuzioni ripetute della suite). */
export function emailUnica(prefisso: string): string {
    return `${prefisso}-${Date.now()}-${Math.floor(Math.random() * 1e4)}@example.com`;
}

/** Login di un account frontend già verificato. */
export async function loginAccount(page: Page, email: string, password: string = ACCOUNT_PASSWORD): Promise<void> {
    await page.goto(`/a/${SLUG}/login`);
    await page.getByLabel(/email/i).fill(email);
    await page.getByLabel(/password/i).fill(password);
    await page.getByRole('button', { name: /accedi/i }).click();
    await expect(page).not.toHaveURL(/\/login$/);
}

/** Login admin nel gestionale (guard web, convive con la guard account). */
export async function loginAdmin(page: Page, email: string = ADMIN.email, password: string = ADMIN.password): Promise<void> {
    await page.goto('/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(password);
    await page.locator('button.btn-login').click();
    await expect(page).toHaveURL(/dashboard/);
}

/** Registra un account dal form pubblico (NON lo verifica). */
export async function registraAccount(page: Page, dati: { nome?: string; cognome?: string; email: string; password?: string }): Promise<void> {
    await page.goto(`/a/${SLUG}/registrati`);
    await page.getByLabel(/nome/i).first().fill(dati.nome ?? 'Test');
    await page.getByLabel(/cognome/i).fill(dati.cognome ?? 'Utente');
    await page.getByLabel(/^email/i).fill(dati.email);
    await page.getByLabel(/^password/i).first().fill(dati.password ?? ACCOUNT_PASSWORD);
    await page.getByLabel(/conferma password/i).fill(dati.password ?? ACCOUNT_PASSWORD);
    await page.getByRole('checkbox').check();
    await page.getByRole('button', { name: /registrati/i }).click();
}

/** Crea un account già verificato via fixture e fa il login. */
export async function accountVerificatoLoggato(page: Page, prefisso: string): Promise<string> {
    const email = emailUnica(prefisso);
    fixture('crea-account', { email });
    await loginAccount(page, email);
    return email;
}

/** Crea un evento pubblicato via fixture. Ritorna { evento_id, titolo }. */
export function creaEvento(opzioni: { titolo?: string; capienza?: number; prezzo?: number; 'posti-prenotati'?: number; passato?: number; 'solo-soci'?: number } = {}): { evento_id: string; titolo: string } {
    return fixture('crea-evento', opzioni as Record<string, string | number>);
}

/**
 * Azzera i contatori del rate limiter: i throttle di produzione (5-10
 * richieste/min per IP) scatterebbero tra un test e l'altro della suite.
 * Da chiamare nel beforeEach degli spec che fanno POST pubbliche.
 */
export function azzeraThrottle(): void {
    fixture('azzera-throttle');
}

/** Nuova sessione ospite: azzera i cookie (cambio account o guard). */
export async function nuovaSessione(page: Page): Promise<void> {
    await page.context().clearCookies();
}

/** Estrae l'id prenotazione dall'URL dell'esito (/prenotazioni/{id}/esito). */
export function idPrenotazioneDaEsito(page: Page): string {
    const match = page.url().match(/\/prenotazioni\/([^/]+)\/esito/);
    if (!match) {
        throw new Error(`URL esito inatteso: ${page.url()}`);
    }
    return match[1];
}

export type StatoPrenotazione = { codice: string; stato: string; posti: number; qr_token: string };

/** Prenotazioni dell'account (via fixture), in ordine di creazione. */
export function statoPrenotazioni(email: string): StatoPrenotazione[] {
    return fixture<{ prenotazioni: StatoPrenotazione[] }>('stato-prenotazioni', { email }).prenotazioni;
}

/** Prenota dal dettaglio evento. Ritorna l'URL di esito. */
export async function prenota(page: Page, eventoId: string, posti = 1): Promise<void> {
    await page.goto(`/a/${SLUG}/eventi/${eventoId}`);
    const selettore = page.getByLabel(/numero di posti/i);
    if (await selettore.isVisible().catch(() => false)) {
        await selettore.selectOption(String(posti));
    }
    await page.getByRole('button', { name: /prenota|lista d'attesa|richiedi la prenotazione/i }).click();
}

export const SOCIO_PASSWORD = 'SocioE2e!2026';

export type SocioConTessera = {
    socio_id: string;
    numero_socio: string;
    tessera_id: string | null;
    numero_tessera: string | null;
    quota_id: string | null;
};

/**
 * Crea via fixture un socio 'Attivo' del tenant e2e con tessera dell'anno
 * (o scaduta / senza tessera / con quota da pagare, a seconda delle opzioni).
 */
export function creaSocioConTessera(
    email: string,
    opzioni: { password?: string; scaduta?: number; 'senza-tessera'?: number; 'con-quota'?: number; 'codice-fiscale'?: string; 'data-nascita'?: string } = {},
): SocioConTessera {
    return fixture<SocioConTessera>('crea-socio-con-tessera', { email, ...opzioni });
}

/** Login del socio nell'area soci (guard 'socio', separata da admin/account). */
export async function loginSocio(page: Page, email: string, password: string = SOCIO_PASSWORD): Promise<void> {
    await page.goto('/area-soci/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(password);
    await page.locator('#pane-login .btn-login').click();
    await expect(page).toHaveURL(/area-soci\/?$/);
}

/**
 * Configura il modulo di iscrizione del tenant e2e. Le opzioni omesse
 * RIPRISTINANO il default (iscrizioni aperte, preiscrizione, config null):
 * chiamarla senza argomenti riporta il tenant allo stato pulito.
 */
export function configuraFormIscrizione(
    opzioni: { aperte?: number; modalita?: 'preiscrizione' | 'diretta'; intro?: string; config?: string } = {},
): void {
    fixture('configura-form-iscrizione', opzioni);
}

export type StatoRichiesta = {
    stato: string;
    motivo_rifiuto: string | null;
    socio_stato: string | null;
    numero_socio: string | null;
    account_socio_id: string | null;
};

/** Ultima richiesta di iscrizione dell'account (via fixture). */
export function statoRichiesta(email: string): StatoRichiesta {
    return fixture<StatoRichiesta>('stato-richiesta', { email });
}

/**
 * POST JSON autenticato dalla sessione corrente della pagina (cookie
 * condivisi con page.request) con il token CSRF letto dal cookie
 * XSRF-TOKEN di Laravel. Usato per gli endpoint scanner/check-in admin.
 */
export async function postJsonConCsrf(
    page: Page,
    url: string,
    payload: Record<string, unknown>,
): Promise<{ status: number; body: Record<string, unknown> }> {
    const cookies = await page.context().cookies();
    const xsrf = cookies.find((c) => c.name === 'XSRF-TOKEN');
    if (!xsrf) {
        throw new Error('Cookie XSRF-TOKEN assente: serve una pagina già visitata nella sessione.');
    }

    const risposta = await page.request.post(url, {
        headers: {
            'X-XSRF-TOKEN': decodeURIComponent(xsrf.value),
            Accept: 'application/json',
        },
        data: payload,
    });

    return { status: risposta.status(), body: (await risposta.json()) as Record<string, unknown> };
}

/**
 * Compila e invia il form pubblico di iscrizione socio (/a/e2e/iscriviti).
 * Compila solo i campi passati: gli altri restano vuoti.
 */
export async function inviaIscrizione(
    page: Page,
    campi: Partial<Record<'telefono' | 'codice_fiscale' | 'data_nascita' | 'citta', string>> = {},
): Promise<void> {
    await page.goto(`/a/${SLUG}/iscriviti`);
    for (const [campo, valore] of Object.entries(campi)) {
        await page.locator(`#campo-${campo}`).fill(valore);
    }
    await page.getByRole('button', { name: /invia la richiesta di iscrizione/i }).click();
}
