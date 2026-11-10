<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Solo le pagine pubbliche del mini-sito finiscono nei motori di ricerca
 * (#45): tutto il resto (pannello, area soci, login, verifiche di tessere e
 * ricevute, pagamenti, link temporanei, anteprime) risponde con
 * X-Robots-Tag: noindex, nofollow. Elenco chiuso: una pagina nuova è
 * esclusa finché non la si aggiunge qui.
 */
class IndicizzazioneMotori
{
    /** @var list<string> */
    public const ROTTE_INDICIZZABILI = [
        'pubblico.home.show',
        'pubblico.eventi.index',
        'pubblico.eventi.show',
        'pubblico.eventi.immagine-social',
        'pubblico.calendario',
        'pubblico.novita',
        'pubblico.iscriviti',
        'pubblico.dona.show',
        'pubblico.trasparenza',
        'pubblico.sitemap',
        'pubblico.feed',
        'pubblico.llms',
        'seo.robots',
        'seo.sitemap',
        'seo.llms',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! in_array($request->route()?->getName(), self::ROTTE_INDICIZZABILI, true)) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
