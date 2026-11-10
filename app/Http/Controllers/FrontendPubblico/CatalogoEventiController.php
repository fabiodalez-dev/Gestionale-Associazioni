<?php

namespace App\Http\Controllers\FrontendPubblico;

use App\Http\Controllers\Controller;
use App\Models\Associazione;
use App\Models\Evento;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Catalogo eventi del frontend pubblico: lista e dettaglio dei SOLI
 * eventi pubblicati del tenant corrente (bozze/archiviati → 404).
 */
class CatalogoEventiController extends Controller
{
    /**
     * Catalogo con ricerca e filtri (#46): testo, periodo, gratuiti o a
     * pagamento, solo con posti liberi. I filtri stanno nell'URL (si può
     * condividere); senza JavaScript è un normale form GET, con JavaScript la
     * stessa rotta restituisce solo l'elenco e la pagina non si ricarica.
     */
    public function index(Request $request, Associazione $associazione): View
    {
        $filtri = [
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 100),
            'periodo' => in_array($request->query('periodo'), ['settimana', 'mese'], true) ? (string) $request->query('periodo') : '',
            'prezzo' => in_array($request->query('prezzo'), ['gratuiti', 'a_pagamento'], true) ? (string) $request->query('prezzo') : '',
            'posti' => $request->boolean('posti'),
        ];

        $query = Evento::query()
            ->where('associazione_id', $associazione->id)
            ->pubblicati()
            ->where('data_evento', '>=', now())
            ->orderBy('data_evento');

        if ($filtri['q'] !== '') {
            foreach (preg_split('/\s+/', $filtri['q']) ?: [] as $parola) {
                $query->where(fn ($w) => $w
                    ->where('titolo', 'like', '%'.$parola.'%')
                    ->orWhere('descrizione', 'like', '%'.$parola.'%')
                    ->orWhere('luogo', 'like', '%'.$parola.'%'));
            }
        }
        if ($filtri['periodo'] === 'settimana') {
            $query->where('data_evento', '<=', now()->endOfWeek());
        } elseif ($filtri['periodo'] === 'mese') {
            $query->where('data_evento', '<=', now()->endOfMonth());
        }
        if ($filtri['prezzo'] === 'gratuiti') {
            $query->where(fn ($w) => $w->whereNull('prezzo')->orWhere('prezzo', '<=', 0));
        } elseif ($filtri['prezzo'] === 'a_pagamento') {
            $query->where('prezzo', '>', 0);
        }

        $eventi = $query->get();
        if ($filtri['posti']) {
            // null = capienza illimitata
            $eventi = $eventi->filter(fn (Evento $evento) => $evento->postiDisponibili() !== 0)->values();
        }

        $dati = [
            'associazione' => $associazione,
            'eventi' => $eventi,
            'filtri' => $filtri,
            'filtriAttivi' => $filtri['q'] !== '' || $filtri['periodo'] !== '' || $filtri['prezzo'] !== '' || $filtri['posti'],
        ];

        return view($request->header('X-Elenco-Eventi') === '1' ? 'pubblico.eventi._elenco' : 'pubblico.eventi.index', $dati);
    }

    public function show(Associazione $associazione, Evento $evento): View
    {
        // 404 uniforme: evento di un altro tenant o non pubblicato.
        // Check esplicito perché il binding implicito può risolversi
        // prima che RisolviTenantPubblico abbia bindato il tenant.
        abort_if($evento->associazione_id !== $associazione->id, 404);
        abort_unless($evento->stato_pubblicazione === Evento::STATO_PUBBLICATO, 404);

        return view('pubblico.eventi.show', [
            'associazione' => $associazione,
            'evento' => $evento,
            'postiDisponibili' => $evento->postiDisponibili(),
        ]);
    }
}
