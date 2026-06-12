<?php

namespace App\Services\AnagraficaPro;

use App\Models\Socio;
use Illuminate\Support\Collection;

/**
 * Rilevazione possibili soci duplicati (f.24 PIANO-VITA-SOCIALE):
 * gruppi di soci dello stesso tenant con stessa email O stesso codice
 * fiscale O stessa terna nome+cognome+data di nascita, su valori
 * normalizzati (trim, case-insensitive, spazi multipli collassati).
 */
class DuplicatiService
{
    public const CRITERIO_EMAIL = 'email';

    public const CRITERIO_CODICE_FISCALE = 'codice_fiscale';

    public const CRITERIO_ANAGRAFICA = 'anagrafica';

    public const CRITERI = [
        self::CRITERIO_EMAIL => 'Stessa email',
        self::CRITERIO_CODICE_FISCALE => 'Stesso codice fiscale',
        self::CRITERIO_ANAGRAFICA => 'Stessi nome, cognome e data di nascita',
    ];

    /**
     * Gruppi di possibili duplicati del tenant. Ogni gruppo:
     * ['criterio' => string, 'valore' => string, 'soci' => Collection<int, Socio>].
     *
     * Lo stesso insieme di soci non viene riproposto due volte se matcha
     * più criteri (dedup sull'insieme degli id).
     *
     * @return list<array{criterio: string, valore: string, soci: Collection<int, Socio>}>
     */
    public function gruppi(string $associazioneId): array
    {
        $soci = Socio::forAssociazione($associazioneId)
            ->with(['ultimaTessera'])
            ->orderBy('cognome')
            ->orderBy('nome')
            ->get();

        $gruppi = [];
        $visti = [];

        foreach ([
            self::CRITERIO_EMAIL => fn (Socio $s) => $this->normalizzaEmail($s->email),
            self::CRITERIO_CODICE_FISCALE => fn (Socio $s) => $this->normalizzaCodiceFiscale($s->codice_fiscale),
            self::CRITERIO_ANAGRAFICA => fn (Socio $s) => $this->chiaveAnagrafica($s),
        ] as $criterio => $estraiChiave) {
            $perChiave = $soci
                ->groupBy(fn (Socio $s) => $estraiChiave($s) ?? '')
                ->forget('')
                ->filter(fn (Collection $gruppo) => $gruppo->count() > 1);

            foreach ($perChiave as $valore => $gruppo) {
                $firma = $gruppo->pluck('id')->sort()->implode('|');

                if (isset($visti[$firma])) {
                    continue;
                }

                $visti[$firma] = true;
                $gruppi[] = [
                    'criterio' => $criterio,
                    'valore' => (string) $valore,
                    'soci' => $gruppo->values(),
                ];
            }
        }

        return $gruppi;
    }

    public function normalizzaEmail(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email === '' ? null : $email;
    }

    public function normalizzaCodiceFiscale(?string $codiceFiscale): ?string
    {
        $cf = mb_strtoupper((string) preg_replace('/\s+/u', '', (string) $codiceFiscale));

        return $cf === '' ? null : $cf;
    }

    private function chiaveAnagrafica(Socio $socio): ?string
    {
        if (blank($socio->nome) || blank($socio->cognome) || $socio->data_nascita === null) {
            return null;
        }

        return $this->normalizzaNome($socio->nome)
            .'|'.$this->normalizzaNome($socio->cognome)
            .'|'.$socio->data_nascita->toDateString();
    }

    private function normalizzaNome(string $valore): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $valore)));
    }
}
