<?php

namespace App\Services\Soci;

use App\Models\Associazione;

/**
 * Campi obbligatori configurabili per associazione
 * (associazioni.campi_obbligatori_config, contesti 'backend' e 'preiscrizione' — spec soci §8).
 */
class RequiredFieldsConfig
{
    public const CONTESTO_BACKEND = 'backend';

    public const CONTESTO_PREISCRIZIONE = 'preiscrizione';

    /** @var list<string> */
    public const CAMPI = [
        'telefono',
        'codice_fiscale',
        'data_nascita',
        'indirizzo',
        'citta',
        'provincia',
        'cap',
        'tipo_socio_id',
        'categoria_socio_id',
        'sede_id',
        'privacy_consenso',
        'note',
    ];

    /** @var array<string, string> */
    public const LABELS = [
        'telefono' => 'Telefono',
        'codice_fiscale' => 'Codice Fiscale',
        'data_nascita' => 'Data di Nascita',
        'indirizzo' => 'Indirizzo',
        'citta' => 'Città',
        'provincia' => 'Provincia',
        'cap' => 'CAP',
        'tipo_socio_id' => 'Tipo Socio',
        'categoria_socio_id' => 'Categoria Socio',
        'sede_id' => 'Sede',
        'privacy_consenso' => 'Consenso Privacy',
        'note' => 'Note',
    ];

    /**
     * Mappa campo => obbligatorio per il contesto richiesto.
     * Default: tutto false tranne data_nascita (e privacy_consenso nel solo contesto preiscrizione).
     * Il JSON salvato fa merge sui default solo per chiavi note.
     *
     * @return array<string, bool>
     */
    public function per(?Associazione $associazione, string $contesto = self::CONTESTO_BACKEND): array
    {
        $defaults = array_fill_keys(self::CAMPI, false);
        $defaults['data_nascita'] = true;

        if ($contesto === self::CONTESTO_PREISCRIZIONE) {
            $defaults['privacy_consenso'] = true;
        }

        $config = $associazione?->iscrizioni()->campi_obbligatori_config;
        $perContesto = is_array($config) ? ($config[$contesto] ?? []) : [];

        foreach ($perContesto as $campo => $valore) {
            if (array_key_exists($campo, $defaults)) {
                $defaults[$campo] = (bool) $valore;
            }
        }

        return $defaults;
    }

    public function isRequired(?Associazione $associazione, string $contesto, string $campo): bool
    {
        return $this->per($associazione, $contesto)[$campo] ?? false;
    }
}
