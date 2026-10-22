<?php

namespace App\Settings;

use App\Models\Associazione;

/**
 * Iscrizione dei soci: apertura, modalità (preiscrizione o diretta), schema
 * del modulo pubblico e campi obbligatori di scheda socio e preiscrizione.
 */
class IscrizioniSettings extends ImpostazioniAssociazione
{
    public bool $iscrizioni_socio_aperte;

    /** Associazione::MODALITA_PREISCRIZIONE o MODALITA_DIRETTA. */
    public string $modalita_iscrizione;

    /** Schema del modulo pubblico (FormIscrizioneService), null = default. */
    public ?array $iscrizione_form_config;

    /** Campi obbligatori per contesto (RequiredFieldsConfig), null = nessuno. */
    public ?array $campi_obbligatori_config;

    public static function group(): string
    {
        return 'iscrizioni';
    }

    public function diretta(): bool
    {
        return $this->modalita_iscrizione === Associazione::MODALITA_DIRETTA;
    }

    protected static function normalizza(array $valori): array
    {
        $modalita = $valori['modalita_iscrizione'] ?? null;

        return [
            'iscrizioni_socio_aperte' => filter_var($valori['iscrizioni_socio_aperte'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'modalita_iscrizione' => in_array($modalita, Associazione::MODALITA_ISCRIZIONE, true)
                ? $modalita
                : Associazione::MODALITA_PREISCRIZIONE,
            'iscrizione_form_config' => is_array($valori['iscrizione_form_config'] ?? null) && $valori['iscrizione_form_config'] !== []
                ? $valori['iscrizione_form_config']
                : null,
            'campi_obbligatori_config' => is_array($valori['campi_obbligatori_config'] ?? null) && $valori['campi_obbligatori_config'] !== []
                ? $valori['campi_obbligatori_config']
                : null,
        ];
    }
}
