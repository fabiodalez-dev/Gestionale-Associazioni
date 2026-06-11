<?php

namespace App\Services\Struttura;

use App\Models\Associazione;

/**
 * Configurazione "campi obbligatori" per tenant (spec struttura §4.2.7).
 *
 * Persistenza: JSON in associazioni.campi_obbligatori_config con struttura
 * {"backend": {campo: bool, ...}, "preiscrizione": {campo: bool, ...}}.
 */
class RequiredFieldsService
{
    /** Campi sempre obbligatori, non configurabili. */
    public const ALWAYS_REQUIRED = ['nome', 'cognome', 'email'];

    /** Campi built-in configurabili per il backend soci. */
    public const BACKEND_FIELDS = [
        'telefono', 'codice_fiscale', 'data_nascita', 'indirizzo', 'citta',
        'provincia', 'cap', 'tipo_socio_id', 'categoria_socio_id', 'sede_id',
        'privacy_consenso', 'note',
    ];

    /** Campi built-in configurabili per la preiscrizione (no sede_id/note, backend-only). */
    public const PREISCRIZIONE_FIELDS = [
        'telefono', 'codice_fiscale', 'data_nascita', 'indirizzo', 'citta',
        'provincia', 'cap', 'tipo_socio_id', 'categoria_socio_id', 'privacy_consenso',
    ];

    /** Etichette UI dei campi configurabili. */
    public const LABELS = [
        'telefono' => 'Telefono',
        'codice_fiscale' => 'Codice fiscale',
        'data_nascita' => 'Data di nascita',
        'indirizzo' => 'Indirizzo',
        'citta' => 'Città',
        'provincia' => 'Provincia',
        'cap' => 'CAP',
        'tipo_socio_id' => 'Tipo socio',
        'categoria_socio_id' => 'Categoria socio',
        'sede_id' => 'Sede',
        'privacy_consenso' => 'Consenso privacy',
        'note' => 'Note',
    ];

    /**
     * Config con default sensati se NULL (nessun campo extra obbligatorio,
     * tranne privacy_consenso in preiscrizione che è SEMPRE true — GDPR).
     *
     * @return array{backend: array<string, bool>, preiscrizione: array<string, bool>}
     */
    public function load(Associazione $associazione): array
    {
        $config = $associazione->campi_obbligatori_config ?? [];

        $backend = [];
        foreach (self::BACKEND_FIELDS as $campo) {
            $backend[$campo] = (bool) ($config['backend'][$campo] ?? false);
        }

        $preiscrizione = [];
        foreach (self::PREISCRIZIONE_FIELDS as $campo) {
            $preiscrizione[$campo] = (bool) ($config['preiscrizione'][$campo] ?? false);
        }

        // privacy_consenso in preiscrizione è SEMPRE obbligatorio (GDPR)
        $preiscrizione['privacy_consenso'] = true;

        return ['backend' => $backend, 'preiscrizione' => $preiscrizione];
    }

    /**
     * @param  array{backend: array<string, bool>, preiscrizione: array<string, bool>}  $config
     */
    public function isRequired(array $config, string $contesto, string $campo): bool
    {
        if (in_array($campo, self::ALWAYS_REQUIRED, true)) {
            return true;
        }

        if ($contesto === 'preiscrizione' && $campo === 'privacy_consenso') {
            return true;
        }

        return (bool) ($config[$contesto][$campo] ?? false);
    }

    /**
     * Normalizza l'input dei checkbox in struttura persistibile.
     *
     * @param  array<string, mixed>  $backendInput
     * @param  array<string, mixed>  $preiscrizioneInput
     * @return array{backend: array<string, bool>, preiscrizione: array<string, bool>}
     */
    public function buildConfig(array $backendInput, array $preiscrizioneInput): array
    {
        $backend = [];
        foreach (self::BACKEND_FIELDS as $campo) {
            $backend[$campo] = filter_var($backendInput[$campo] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        $preiscrizione = [];
        foreach (self::PREISCRIZIONE_FIELDS as $campo) {
            $preiscrizione[$campo] = filter_var($preiscrizioneInput[$campo] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        // Forzatura GDPR server-side, indipendente dall'input
        $preiscrizione['privacy_consenso'] = true;

        return ['backend' => $backend, 'preiscrizione' => $preiscrizione];
    }
}
