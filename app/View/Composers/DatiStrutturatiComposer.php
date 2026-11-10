<?php

namespace App\View\Composers;

use App\Models\Associazione;
use App\Models\Evento;
use App\Support\SchemaOrg;
use Illuminate\View\View;

/**
 * Dati strutturati JSON-LD delle pagine pubbliche del mini-sito (#40, #44):
 * il layout stampa $datiStrutturati, una stringa JSON per blocco <script>.
 */
class DatiStrutturatiComposer
{
    /** Pagine con le sole briciole: vista => [etichetta, rotta]. */
    private const BRICIOLE = [
        'pubblico.eventi.index' => ['Eventi', 'pubblico.eventi.index'],
        'comunicazione.pubblico.novita' => ['Novità', 'pubblico.novita'],
        'pubblico.trasparenza' => ['Trasparenza', 'pubblico.trasparenza'],
    ];

    public function compose(View $view): void
    {
        $associazione = $view->getData()['associazione'] ?? null;
        if (! $associazione instanceof Associazione) {
            return;
        }

        $home = route('pubblico.home.show', $associazione->slug);
        $blocchi = [];

        if ($view->name() === 'pubblico.home') {
            $blocchi[] = SchemaOrg::associazione($associazione);
        } elseif ($view->name() === 'pubblico.eventi.show' && ($evento = $view->getData()['evento'] ?? null) instanceof Evento) {
            $posti = $view->getData()['postiDisponibili'] ?? null;
            $blocchi[] = SchemaOrg::evento($evento, $associazione, is_int($posti) ? $posti : null);
            $blocchi[] = SchemaOrg::briciole([
                $associazione->nome => $home,
                'Eventi' => route('pubblico.eventi.index', $associazione->slug),
                $evento->titolo => route('pubblico.eventi.show', [$associazione->slug, $evento->id]),
            ]);
        } elseif (isset(self::BRICIOLE[$view->name()])) {
            [$etichetta, $rotta] = self::BRICIOLE[$view->name()];
            $blocchi[] = SchemaOrg::briciole([$associazione->nome => $home, $etichetta => route($rotta, $associazione->slug)]);
        }

        $view->with('datiStrutturati', array_map(SchemaOrg::json(...), $blocchi));
    }
}
