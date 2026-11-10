<?php

namespace App\Health;

use Illuminate\Support\Facades\Http;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Riscritture degli indirizzi (#32): su IONOS la home funzionava ma /login
 * dava 500, perché sul server era rimasto un public/.htaccess vecchio. Dal
 * pannello non c'era modo di accorgersene. Il controllo chiede al sito
 * stesso /up (indirizzo "pulito", passa dal .htaccess) e, se fallisce,
 * /public/index.php/up (front controller diretto): se il secondo risponde e
 * il primo no, il problema sono le riscritture. Se il server non raggiunge
 * sé stesso il controllo non dà allarmi.
 */
class RiscrittureIndirizziCheck extends Check
{
    public function run(): Result
    {
        $base = rtrim((string) config('app.url'), '/');
        $risultato = Result::make()->meta(['base' => $base]);

        $pulito = $this->stato($base.'/up');
        if ($pulito === 200) {
            $barra = $this->redirect($base.'/up/');
            if ($barra !== null && ! str_contains($base, '/public') && str_contains($barra, '/public/')) {
                return $risultato->shortSummary('/public')->warning(
                    "Gli indirizzi con la barra finale reindirizzano a {$barra}, mostrando /public: ricarica public/.htaccess dalla versione installata."
                );
            }

            return $risultato->shortSummary('ok')->ok();
        }

        $frontController = $base.(str_ends_with($base, '/public') ? '' : '/public').'/index.php/up';
        if ($this->stato($frontController) === 200) {
            return $risultato->shortSummary('rotte')->failed(
                "Le pagine rispondono solo passando da index.php ({$frontController}): le riscritture degli indirizzi non funzionano. "
                .'Ricarica via FTP .htaccess e public/.htaccess dalla versione installata (sono file nascosti).'
            );
        }

        if ($pulito === null) {
            return $risultato->shortSummary('non verificabile')->ok('Il server non raggiunge sé stesso: controllo non eseguibile da qui.');
        }

        return $risultato->shortSummary((string) $pulito)->warning("{$base}/up risponde {$pulito}.");
    }

    /** Codice HTTP, o null se il server non è raggiungibile. */
    private function stato(string $url): ?int
    {
        try {
            return Http::timeout(5)->withoutRedirecting()->get($url)->status();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Destinazione di un redirect, o null se non c'è redirect. */
    private function redirect(string $url): ?string
    {
        try {
            $risposta = Http::timeout(5)->withoutRedirecting()->get($url);

            return $risposta->redirect() ? $risposta->header('Location') : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
