<?php

namespace App\Services\Pagamenti;

/**
 * Entità incassabile via gateway (Stripe/PayPal): oggi Quota e Prenotazione.
 *
 * I gateway lavorano SOLO su questa astrazione; la correlazione del webhook
 * avviene tramite:
 * - Stripe → metadata {tipo, pagabile_id, associazione_id, quota_id solo se tipo=quota};
 * - PayPal → custom_id ("prenotazione:{uuid}" per le prenotazioni,
 *   UUID nudo per le quote — retrocompatibilità con le sessioni legacy in volo).
 */
interface Pagabile
{
    public const TIPO_QUOTA = 'quota';

    public const TIPO_PRENOTAZIONE = 'prenotazione';

    /**
     * Id (UUID) dell'entità da pagare.
     */
    public function getPagabileId(): string;

    /**
     * Tipo di pagabile: self::TIPO_QUOTA o self::TIPO_PRENOTAZIONE.
     */
    public function getPagabileTipo(): string;

    /**
     * Tenant proprietario (lookup config gateway per-associazione).
     */
    public function getAssociazioneId(): string;

    /**
     * Importo totale da incassare (EUR o valuta del tenant).
     */
    public function getImporto(): float;

    /**
     * Riga descrittiva mostrata sul checkout del gateway.
     */
    public function getDescrizioneRiga(): string;

    /**
     * Email del pagatore (precompila il checkout), null se non disponibile.
     */
    public function getEmailPagatore(): ?string;
}
