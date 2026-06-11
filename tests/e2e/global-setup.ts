import { fixture } from './helpers';

/**
 * Prepara il database e2e una volta per run: migrate:fresh + ruoli +
 * fixture deterministiche (associazione 'e2e', admin, account verificato).
 */
export default function globalSetup(): void {
    const esito = fixture<{ slug?: string; error?: string }>('reset');
    if (esito.error || esito.slug !== 'e2e') {
        throw new Error(`Reset E2E fallito: ${JSON.stringify(esito)}`);
    }
    // eslint-disable-next-line no-console
    console.log('DB e2e pronto (associazione: e2e)');
}
