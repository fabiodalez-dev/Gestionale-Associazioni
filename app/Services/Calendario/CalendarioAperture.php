<?php

namespace App\Services\Calendario;

use App\Models\Chiusura;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Quando l'associazione è aperta (#34): festività nazionali italiane
 * calcolate da regole (sempre valide, senza configurazione) più le chiusure
 * inserite dall'associazione, per tutte le sedi o per una. Usato dalle
 * scadenze, dagli spazi e dalle attrezzature per non cadere in un giorno
 * di chiusura.
 */
class CalendarioAperture
{
    /** @var array<string, Collection<int, Chiusura>> */
    private array $chiusure = [];

    /**
     * Festività nazionali dell'anno: data (Y-m-d) => nome.
     *
     * @return array<string, string>
     */
    public static function festivita(int $anno): array
    {
        $pasqua = self::pasqua($anno);

        $giorni = [
            "{$anno}-01-01" => 'Capodanno',
            "{$anno}-01-06" => 'Epifania',
            $pasqua->toDateString() => 'Pasqua',
            $pasqua->addDay()->toDateString() => 'Lunedì dell\'Angelo',
            "{$anno}-04-25" => 'Festa della Liberazione',
            "{$anno}-05-01" => 'Festa del Lavoro',
            "{$anno}-06-02" => 'Festa della Repubblica',
            "{$anno}-08-15" => 'Ferragosto',
            "{$anno}-11-01" => 'Ognissanti',
            "{$anno}-12-08" => 'Immacolata Concezione',
            "{$anno}-12-25" => 'Natale',
            "{$anno}-12-26" => 'Santo Stefano',
        ];
        ksort($giorni);

        return $giorni;
    }

    /** Domenica di Pasqua (calendario gregoriano, algoritmo di Meeus/Jones/Butcher). */
    public static function pasqua(int $anno): CarbonImmutable
    {
        $a = $anno % 19;
        $b = intdiv($anno, 100);
        $c = $anno % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mese = intdiv($h + $l - 7 * $m + 114, 31);
        $giorno = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($anno, $mese, $giorno)->startOfDay();
    }

    /**
     * Perché quel giorno è chiuso, oppure null se è aperto. Una chiusura
     * senza sede vale per tutte; con sede, solo per quella.
     */
    public function motivoChiusura(CarbonInterface $data, string $associazioneId, ?string $sedeId = null): ?string
    {
        $giorno = $data->toDateString();

        $festa = self::festivita((int) $data->format('Y'))[$giorno] ?? null;
        if ($festa !== null) {
            return $festa;
        }

        foreach ($this->chiusureDi($associazioneId) as $chiusura) {
            if (($chiusura->sede_id === null || $chiusura->sede_id === $sedeId) && $chiusura->comprende($giorno)) {
                return $chiusura->titolo;
            }
        }

        return null;
    }

    public function aperto(CarbonInterface $data, string $associazioneId, ?string $sedeId = null): bool
    {
        return $this->motivoChiusura($data, $associazioneId, $sedeId) === null;
    }

    /** Il giorno stesso se è aperto, altrimenti il primo aperto dopo (entro un anno). */
    public function primoGiornoAperto(CarbonInterface $data, string $associazioneId, ?string $sedeId = null): CarbonImmutable
    {
        $giorno = CarbonImmutable::instance($data);
        for ($i = 0; $i < 366; $i++) {
            if ($this->aperto($giorno, $associazioneId, $sedeId)) {
                return $giorno;
            }
            $giorno = $giorno->addDay();
        }

        // Chiuso tutto l'anno: meglio la data originale che un ciclo infinito
        return CarbonImmutable::instance($data);
    }

    /**
     * Una scadenza che cade in un giorno chiuso diventa la fine del primo
     * giorno aperto (#35, come Pinakes): chi deve agire ha un giorno intero
     * in cui l'associazione risponde. Una scadenza in un giorno aperto resta
     * com'è.
     */
    public function scadenzaInGiornoAperto(CarbonInterface $scadenza, string $associazioneId, ?string $sedeId = null): CarbonImmutable
    {
        $scadenza = CarbonImmutable::instance($scadenza);
        if ($this->aperto($scadenza, $associazioneId, $sedeId)) {
            return $scadenza;
        }

        return $this->primoGiornoAperto($scadenza->startOfDay(), $associazioneId, $sedeId)->endOfDay();
    }

    /**
     * Prossime festività e chiusure attive, per mostrarle in configurazione.
     *
     * @return list<array{data: string, nome: string}>
     */
    public static function prossimeFestivita(CarbonInterface $da, int $quante = 8): array
    {
        $risultato = [];
        foreach ([(int) $da->format('Y'), (int) $da->format('Y') + 1] as $anno) {
            foreach (self::festivita($anno) as $data => $nome) {
                if ($data >= $da->toDateString() && count($risultato) < $quante) {
                    $risultato[] = ['data' => $data, 'nome' => $nome];
                }
            }
        }

        return $risultato;
    }

    /** @return Collection<int, Chiusura> */
    private function chiusureDi(string $associazioneId): Collection
    {
        return $this->chiusure[$associazioneId] ??= Chiusura::query()
            ->withoutGlobalScopes()
            ->where('associazione_id', $associazioneId)
            ->where('attiva', true)
            ->get();
    }
}
