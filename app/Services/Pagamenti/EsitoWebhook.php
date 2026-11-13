<?php

namespace App\Services\Pagamenti;

/**
 * Esito dell'elaborazione di un webhook di pagamento (Stripe/PayPal).
 *
 * - OK: evento elaborato (o replay già noto) → ack 200 al gateway;
 * - IGNORATO: evento firmato ma incoerente con il pagabile (importo,
 *   valuta, stato del pagamento, modalità live/test) → nessun accredito,
 *   warning nel log e ack 200 con esito "ignored", perché un retry del
 *   gateway non cambierebbe nulla;
 * - FALLITO: evento non verificabile o non elaborabile → 400, il gateway
 *   ritenterà.
 */
enum EsitoWebhook: string
{
    case OK = 'ok';

    case IGNORATO = 'ignored';

    case FALLITO = 'failed';
}
