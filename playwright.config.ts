import { defineConfig } from '@playwright/test';

/**
 * Suite E2E del sistema di prenotazione eventi.
 * Avvio: npm run test:e2e (prepara il DB e2e, avvia il server su :8011, esegue i 25 test).
 */
export default defineConfig({
    testDir: './tests/e2e/specs',
    globalSetup: './tests/e2e/global-setup.ts',
    timeout: 30_000,
    expect: { timeout: 7_000 },
    // I test condividono il DB seedato: niente parallelismo tra file per evitare
    // interferenze sui posti/prenotazioni (ogni spec usa comunque dati propri).
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: [['list'], ['html', { open: 'never', outputFolder: 'tests/e2e/report' }]],
    use: {
        baseURL: 'http://127.0.0.1:8011',
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
        locale: 'it-IT',
    },
    webServer: {
        command: 'bash tests/e2e/serve.sh',
        url: 'http://127.0.0.1:8011/up',
        reuseExistingServer: false,
        timeout: 30_000,
    },
});
