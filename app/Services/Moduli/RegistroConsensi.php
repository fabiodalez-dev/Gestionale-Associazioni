<?php

namespace App\Services\Moduli;

use App\Models\Associazione;
use App\Models\Consenso;
use App\Models\Socio;
use App\Settings\ModuliSettings;

/**
 * Consensi dei moduli pubblici: regole di validazione dai consensi
 * configurati dall'associazione e registrazione con il testo esatto mostrato.
 */
class RegistroConsensi
{
    public const TESTO_PRIVACY = 'Ho letto e accetto l\'informativa sul trattamento dei dati personali.';

    /**
     * @return array<string, list<string>>
     */
    public function regole(Associazione $associazione): array
    {
        $regole = ['consensi' => ['nullable', 'array']];

        foreach ($associazione->impostazioni(ModuliSettings::class)->elencoConsensi() as $consenso) {
            $regole['consensi.'.$consenso['chiave']] = $consenso['obbligatorio'] ? ['accepted'] : ['nullable', 'boolean'];
        }

        return $regole;
    }

    /**
     * @return array<string, string>
     */
    public function messaggi(Associazione $associazione): array
    {
        $messaggi = [];
        foreach ($associazione->impostazioni(ModuliSettings::class)->elencoConsensi() as $consenso) {
            $messaggi['consensi.'.$consenso['chiave'].'.accepted'] = 'Per proseguire è necessario accettare: '.$consenso['testo'];
        }

        return $messaggi;
    }

    /**
     * Registra privacy e consensi aggiuntivi (anche quelli non dati).
     *
     * @param  array<string, mixed>  $risposte  chiave => valore inviato
     */
    public function registra(Associazione $associazione, Socio $socio, array $risposte, string $origine, ?string $ip): void
    {
        $base = ['associazione_id' => $associazione->id, 'socio_id' => $socio->id, 'origine' => $origine, 'ip' => $ip];

        Consenso::query()->withoutGlobalScopes()->create($base + [
            'chiave' => Consenso::CHIAVE_PRIVACY,
            'testo' => self::TESTO_PRIVACY,
            'accettato' => true,
        ]);

        foreach ($associazione->impostazioni(ModuliSettings::class)->elencoConsensi() as $consenso) {
            Consenso::query()->withoutGlobalScopes()->create($base + [
                'chiave' => $consenso['chiave'],
                'testo' => $consenso['testo'],
                'accettato' => filter_var($risposte[$consenso['chiave']] ?? false, FILTER_VALIDATE_BOOLEAN),
            ]);
        }
    }
}
