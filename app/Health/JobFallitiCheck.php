<?php

namespace App\Health;

use Illuminate\Support\Facades\DB;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Job falliti nelle ultime 24 ore: avviso da 1, errore oltre la soglia.
 */
class JobFallitiCheck extends Check
{
    private int $sogliaErrore = 10;

    public function failWhenMoreThan(int $soglia): self
    {
        $this->sogliaErrore = $soglia;

        return $this;
    }

    public function run(): Result
    {
        $numero = DB::table((string) config('queue.failed.table', 'failed_jobs'))
            ->where('failed_at', '>=', now()->subDay())
            ->count();

        $risultato = Result::make()
            ->meta(['falliti_24h' => $numero])
            ->shortSummary((string) $numero);

        if ($numero > $this->sogliaErrore) {
            return $risultato->failed("{$numero} job falliti nelle ultime 24 ore.");
        }

        if ($numero > 0) {
            return $risultato->warning("{$numero} job falliti nelle ultime 24 ore.");
        }

        return $risultato->ok();
    }
}
