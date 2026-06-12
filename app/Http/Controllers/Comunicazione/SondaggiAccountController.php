<?php

namespace App\Http\Controllers\Comunicazione;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comunicazione\VotoSondaggioRequest;
use App\Models\Account;
use App\Models\Associazione;
use App\Models\Sondaggio;
use App\Services\Comunicazione\SondaggiVotoService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Sondaggi nell'area riservata account del mini-sito (f.11): i sondaggi
 * solo_soci sono votabili solo dagli account collegati a un socio (che
 * votano come quel Socio — identità unica tra le due aree); gli altri
 * account vedono e votano solo i sondaggi non riservati.
 */
class SondaggiAccountController extends Controller
{
    public function __construct(
        private readonly SondaggiVotoService $voto,
    ) {}

    public function index(Associazione $associazione, Request $request): View
    {
        /** @var Account $account */
        $account = $request->user('account');

        $sondaggi = $this->visibili($account)
            ->orderByDesc('aperto_dal')
            ->get()
            ->map(fn (Sondaggio $sondaggio) => [
                'sondaggio' => $sondaggio,
                'risposta' => $this->voto->rispostaDi($sondaggio, $account),
                'risultati' => $sondaggio->risultati(),
            ]);

        return view('comunicazione.pubblico.sondaggi', [
            'associazione' => $associazione,
            'account' => $account,
            'sondaggi' => $sondaggi,
        ]);
    }

    public function vota(Associazione $associazione, Sondaggio $sondaggio, VotoSondaggioRequest $request): RedirectResponse
    {
        /** @var Account $account */
        $account = $request->user('account');

        // I sondaggi riservati non sono raggiungibili dagli account non collegati
        if ($sondaggio->solo_soci && $account->socio_id === null) {
            abort(404);
        }

        $esito = $this->voto->vota($sondaggio, $account, (int) $request->validated('opzione'));

        return redirect()
            ->route('pubblico.account.sondaggi', $associazione->slug)
            ->with(...$this->messaggio($esito));
    }

    /**
     * @return Builder<Sondaggio>
     */
    private function visibili(Account $account)
    {
        $query = Sondaggio::query()->aperti();

        if ($account->socio_id === null) {
            $query->where('solo_soci', false);
        }

        return $query;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function messaggio(string $esito): array
    {
        return match ($esito) {
            SondaggiVotoService::ESITO_OK => ['success', 'Grazie! Il tuo voto è stato registrato.'],
            SondaggiVotoService::ESITO_GIA_VOTATO => ['warning', 'Hai già votato questo sondaggio.'],
            SondaggiVotoService::ESITO_CHIUSO => ['warning', 'Il sondaggio non è aperto al voto.'],
            SondaggiVotoService::ESITO_NON_AMMESSO => ['warning', 'Questo sondaggio è riservato ai soci.'],
            default => ['danger', 'Voto non valido.'],
        };
    }
}
