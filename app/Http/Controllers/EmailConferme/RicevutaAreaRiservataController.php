<?php

namespace App\Http\Controllers\EmailConferme;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Associazione;
use App\Models\Quota;
use App\Models\Socio;
use App\Services\Pagamenti\RicevutaService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Download della ricevuta quota numerata (f.21 PIANO-VITA-SOCIALE)
 * dalle aree riservate:
 *
 * - area soci (guard 'socio'): solo le proprie quote pagate;
 * - area account del frontend pubblico (guard 'account'): solo le quote
 *   del socio collegato all'account.
 *
 * 404 uniforme per quote altrui o non pagate (nessun information leak).
 * La ricevuta viene emessa on demand (idempotente) per coprire i
 * pagamenti registrati prima della funzionalità.
 */
class RicevutaAreaRiservataController extends Controller
{
    public function __construct(private readonly RicevutaService $ricevute) {}

    /**
     * GET /area-soci/quote/{quota}/ricevuta
     */
    public function areaSoci(Request $request, Quota $quota): Response
    {
        /** @var Socio $socio */
        $socio = $request->user('socio');

        abort_if($quota->socio_id !== $socio->id, 404);

        return $this->pdf($quota);
    }

    /**
     * GET /a/{slug}/account/quote/{quota}/ricevuta
     */
    public function account(Request $request, Associazione $associazione, Quota $quota): Response
    {
        /** @var Account $account */
        $account = $request->user('account');

        abort_if($account->socio_id === null, 404);
        abort_if($quota->socio_id !== $account->socio_id, 404);

        return $this->pdf($quota);
    }

    protected function pdf(Quota $quota): Response
    {
        abort_unless($quota->isPagata(), 404);

        $ricevuta = $this->ricevute->emettiPerQuota($quota);

        abort_if($ricevuta === null, 404);

        return response($this->ricevute->pdfContent($ricevuta), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->ricevute->nomeFile($ricevuta).'"',
        ]);
    }
}
