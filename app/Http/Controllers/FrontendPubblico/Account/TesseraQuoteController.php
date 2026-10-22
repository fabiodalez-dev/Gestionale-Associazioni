<?php

namespace App\Http\Controllers\FrontendPubblico\Account;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Associazione;
use App\Models\Quota;
use App\Models\Socio;
use App\Models\Tessera;
use App\Services\AreaSoci\TesseraQrService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Tessera + quote nell'area riservata account: visibile SOLO se l'account
 * è collegato a un socio (socio_id, collegamento sempre esplicito lato
 * admin). Tessera attiva con QR (servizio area-soci esistente) e quote
 * con link di pagamento pubblico (payment_token, riuso pagamento.show).
 */
class TesseraQuoteController extends Controller
{
    public function show(Request $request, Associazione $associazione): View
    {
        /** @var Account $account */
        $account = $request->user('account');

        // 404 uniforme: la pagina esiste solo per gli account collegati a un socio
        abort_if($account->socio_id === null, 404);

        /** @var Socio $socio */
        $socio = $account->socio()
            ->withoutGlobalScope('associazione')
            ->where('associazione_id', $account->associazione_id)
            ->firstOrFail();

        // Tessera attiva più recente (QR generato dal servizio area-soci)
        /** @var Tessera|null $tessera */
        $tessera = $socio->tessere()
            ->where('stato', Tessera::STATO_ATTIVA)
            ->orderByDesc('anno_validita')
            ->orderByDesc('data_emissione')
            ->first();

        $quote = $socio->quote()
            ->orderByDesc('anno')
            ->orderByDesc('data_scadenza')
            ->get();

        // Finestra "In Scadenza" configurata dall'associazione (default 30gg)
        $giorniPreavviso = $associazione->tesseramento()->giorni_notifica_scadenza;

        return view('pubblico.account.tessera', [
            'associazione' => $associazione,
            'account' => $account,
            'socio' => $socio,
            'tessera' => $tessera,
            'tesseraQr' => $tessera !== null ? TesseraQrService::svgDataUri($tessera) : null,
            'quote' => $quote,
            'giorniPreavviso' => $giorniPreavviso,
            // Link di pagamento pubblico per le quote con token valido
            'linkPagamento' => $quote
                ->filter(fn (Quota $q) => $q->hasValidPaymentToken())
                ->mapWithKeys(fn (Quota $q) => [
                    $q->id => route('pagamento.show', ['token' => $q->payment_token]),
                ]),
        ]);
    }
}
