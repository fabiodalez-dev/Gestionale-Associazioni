<?php

namespace App\Http\Controllers\FrontendPubblico;

use App\Http\Controllers\Controller;
use App\Models\Associazione;
use App\Models\Evento;
use Illuminate\View\View;

/**
 * Catalogo eventi del frontend pubblico: lista e dettaglio dei SOLI
 * eventi pubblicati del tenant corrente (bozze/archiviati → 404).
 */
class CatalogoEventiController extends Controller
{
    public function index(Associazione $associazione): View
    {
        $eventi = Evento::query()
            ->where('associazione_id', $associazione->id)
            ->pubblicati()
            ->where('data_evento', '>=', now())
            ->orderBy('data_evento')
            ->get();

        return view('pubblico.eventi.index', [
            'associazione' => $associazione,
            'eventi' => $eventi,
        ]);
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
