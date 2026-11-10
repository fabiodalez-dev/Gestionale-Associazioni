<?php

namespace App\Http\Controllers\FrontendPubblico;

use App\Http\Controllers\Controller;
use App\Models\Annuncio;
use App\Models\Associazione;
use App\Models\Evento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;

/**
 * File per motori di ricerca, lettori di feed e assistenti AI (#39, #41, #42):
 * sitemap, RSS e llms.txt della piattaforma e di ogni mini-sito, generati dai
 * soli dati pubblici. Un'associazione disattivata o con il frontend spento
 * non compare da nessuna parte (RisolviTenantPubblico risponde 404).
 */
class SeoController extends Controller
{
    /** Eventi passati che restano in sitemap: le pagine esistono ancora. */
    private const MESI_EVENTI_PASSATI = 12;

    public function robots(): Response
    {
        $testo = "User-agent: *\nDisallow:\n\nSitemap: ".route('seo.sitemap')."\n";

        return response($testo, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** Indice delle sitemap: una per mini-sito attivo. */
    public function sitemapIndice(): Response
    {
        $associazioni = $this->associazioniPubbliche()->get(['id', 'slug', 'updated_at']);

        return response()
            ->view('seo.sitemap-indice', ['associazioni' => $associazioni])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function sitemap(Associazione $associazione): Response
    {
        $eventi = $this->eventi($associazione)
            ->where('data_evento', '>=', now()->subMonths(self::MESI_EVENTI_PASSATI))
            ->orderBy('data_evento')
            ->get(['id', 'updated_at']);

        $ultimoAnnuncio = $this->annunci($associazione)->max('updated_at');

        return response()
            ->view('seo.sitemap', [
                'associazione' => $associazione,
                'eventi' => $eventi,
                'ultimoAnnuncio' => $ultimoAnnuncio,
            ])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    /** RSS 2.0: prossimi eventi e novità pubbliche, i più recenti per primi. */
    public function feed(Associazione $associazione): Response
    {
        $voci = collect();

        foreach ($this->eventi($associazione)->where('data_evento', '>=', now()->startOfDay())->orderBy('data_evento')->limit(30)->get() as $evento) {
            $voci->push([
                'titolo' => $evento->titolo.' — '.$evento->data_evento->format('d/m/Y H:i'),
                'link' => route('pubblico.eventi.show', [$associazione->slug, $evento->id]),
                'guid' => 'evento-'.$evento->id,
                'descrizione' => (string) $evento->descrizione,
                'data' => $evento->created_at ?? $evento->updated_at ?? now(),
            ]);
        }

        foreach ($this->annunci($associazione)->orderByDesc('pubblicato_dal')->limit(20)->get() as $annuncio) {
            $voci->push([
                'titolo' => $annuncio->titolo,
                'link' => route('pubblico.novita', $associazione->slug).'#annuncio-'.$annuncio->id,
                'guid' => 'annuncio-'.$annuncio->id,
                'descrizione' => strip_tags((string) $annuncio->corpo_html),
                'data' => $annuncio->pubblicato_dal ?? $annuncio->created_at ?? now(),
            ]);
        }

        return response()
            ->view('seo.feed', [
                'associazione' => $associazione,
                'voci' => $voci->sortByDesc(fn (array $v) => $v['data']->getTimestamp())->values(),
            ])
            ->header('Content-Type', 'application/rss+xml; charset=UTF-8');
    }

    public function llmsPiattaforma(): Response
    {
        return response()
            ->view('seo.llms-piattaforma', ['associazioni' => $this->associazioniPubbliche()->orderBy('nome')->get(['id', 'nome', 'slug', 'citta'])])
            ->header('Content-Type', 'text/markdown; charset=UTF-8');
    }

    public function llms(Associazione $associazione): Response
    {
        return response()
            ->view('seo.llms', [
                'associazione' => $associazione,
                'eventi' => $this->eventi($associazione)->where('data_evento', '>=', now()->startOfDay())->orderBy('data_evento')->limit(20)->get(),
            ])
            ->header('Content-Type', 'text/markdown; charset=UTF-8');
    }

    /** @return Builder<Associazione> */
    private function associazioniPubbliche(): Builder
    {
        return Associazione::query()->where('attiva', true)->where('frontend_pubblico_attivo', true);
    }

    /** @return Builder<Evento> */
    private function eventi(Associazione $associazione): Builder
    {
        return Evento::query()->where('associazione_id', $associazione->id)->pubblicati();
    }

    /** @return Builder<Annuncio> */
    private function annunci(Associazione $associazione): Builder
    {
        return Annuncio::query()->where('associazione_id', $associazione->id)->pubblici();
    }
}
