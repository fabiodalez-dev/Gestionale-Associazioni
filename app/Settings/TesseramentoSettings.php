<?php

namespace App\Settings;

use App\Models\Associazione;
use App\Models\Tessera;

/**
 * Tesseramento dell'associazione: scadenza delle tessere, preavviso, testo
 * dell'email di scadenza e step dei promemoria di rinnovo.
 */
class TesseramentoSettings extends ImpostazioniAssociazione
{
    public const GIORNI_PREAVVISO_DEFAULT = 30;

    /** 'solare' (31 dicembre) o 'annuale' (365 giorni dall'emissione). */
    public string $tipo_scadenza_default;

    /** Giorni prima della scadenza da cui una tessera è "in scadenza". */
    public int $giorni_notifica_scadenza;

    public ?string $template_email_scadenza;

    /** @var list<int>|null Giorni prima della scadenza per i promemoria (null = default). */
    public ?array $promemoria_rinnovo_config;

    public static function group(): string
    {
        return 'tesseramento';
    }

    public function scadenzaAnnuale(): bool
    {
        return $this->tipo_scadenza_default === Tessera::TIPO_SCADENZA_ANNUALE;
    }

    /**
     * Step dei promemoria di rinnovo, decrescenti.
     *
     * @return list<int>
     */
    public function promemoriaRinnovoSteps(): array
    {
        $steps = $this->promemoria_rinnovo_config ?: Associazione::PROMEMORIA_RINNOVO_DEFAULT;
        $steps = array_values(array_unique(array_map('intval', $steps)));
        rsort($steps);

        return $steps;
    }

    protected static function normalizza(array $valori): array
    {
        $tipo = $valori['tipo_scadenza_default'] ?? null;
        $giorni = (int) ($valori['giorni_notifica_scadenza'] ?? 0);
        $promemoria = $valori['promemoria_rinnovo_config'] ?? null;

        return [
            'tipo_scadenza_default' => in_array($tipo, [Tessera::TIPO_SCADENZA_SOLARE, Tessera::TIPO_SCADENZA_ANNUALE], true)
                ? $tipo
                : Tessera::TIPO_SCADENZA_SOLARE,
            'giorni_notifica_scadenza' => $giorni > 0 ? $giorni : self::GIORNI_PREAVVISO_DEFAULT,
            'template_email_scadenza' => filled($valori['template_email_scadenza'] ?? null)
                ? (string) $valori['template_email_scadenza']
                : null,
            'promemoria_rinnovo_config' => is_array($promemoria) && $promemoria !== []
                ? array_values(array_map('intval', $promemoria))
                : null,
        ];
    }
}
