<?php

namespace App\Http\Controllers\Soci;

use App\Http\Controllers\Controller;
use App\Http\Requests\Soci\ConfermaPagamentoRequest;
use App\Models\Quota;
use App\Models\Socio;
use App\Services\Soci\EmailBenvenutoService;
use App\Services\Soci\SocioActivityLogger;
use App\Services\Soci\TesseraAutomaticaService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Macchina a stati del flusso pre-iscrizione (spec soci §2):
 * In Attesa → [Approva] → In Attesa di Pagamento → [Conferma] → Attivo
 * In Attesa / In Attesa di Pagamento → [Rifiuta] → eliminazione definitiva.
 */
class SocioStatoController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly SocioActivityLogger $activityLogger,
        private readonly TesseraAutomaticaService $tessere,
        private readonly EmailBenvenutoService $emailBenvenuto,
    ) {}

    /**
     * Approvazione pre-iscrizione (spec §2.3): In Attesa → In Attesa di Pagamento.
     */
    public function approva(Socio $socio): RedirectResponse
    {
        $this->authorize('update', $socio);

        // Transizione atomica: verifica dello stato di partenza nell'UPDATE stesso
        $aggiornati = Socio::query()
            ->whereKey($socio->id)
            ->where('stato', Socio::STATO_IN_ATTESA)
            ->update(['stato' => Socio::STATO_IN_ATTESA_DI_PAGAMENTO]);

        if ($aggiornati === 0) {
            return back()->with('error', 'Socio non trovato o stato non valido.');
        }

        $this->activityLogger->log(
            $socio->refresh(),
            SocioActivityLogger::TIPO_APPROVAZIONE,
            'Pre-iscrizione approvata, in attesa di pagamento.'
        );

        return back()->with('success', "Pre-iscrizione di {$socio->nome_completo} approvata: in attesa di pagamento.");
    }

    /**
     * Conferma pagamento (spec §2.4): metodo manuale → quota pagata + attivazione
     * + tessera + email benvenuto; metodo online → quota da pagare + link pagamento
     * (il socio resta In Attesa di Pagamento, lo attiverà il webhook).
     */
    public function confermaPagamento(ConfermaPagamentoRequest $request, Socio $socio): RedirectResponse
    {
        $this->authorize('update', $socio);

        if ($socio->stato !== Socio::STATO_IN_ATTESA_DI_PAGAMENTO) {
            return back()->with('error', 'Socio non trovato o stato non valido.');
        }

        $importo = (float) $request->validated('importo');
        $metodo = (string) $request->validated('metodo');
        $tipo = $request->validated('tipo') ?: 'Quota Associativa';
        $dataPagamento = $request->validated('data_pagamento') ?: now()->toDateString();

        if ($request->isMetodoOnline()) {
            $token = Str::random(64);

            Quota::create([
                'socio_id' => $socio->id,
                'associazione_id' => $socio->associazione_id,
                'anno' => (int) now()->year,
                'importo' => $importo,
                'data_scadenza' => now()->endOfYear()->toDateString(),
                'stato' => Quota::STATO_DA_PAGARE,
                'tipo' => $tipo,
                'metodo_pagamento' => $metodo,
                'gateway' => $metodo,
                'payment_token' => $token,
                'payment_token_expires' => now()->addDays(7),
            ]);

            $link = Route::has('pagamento.show')
                ? route('pagamento.show', ['token' => $token])
                : url('/pagamento?token='.$token);

            return back()
                ->with('success', 'Quota creata: invia al socio il link di pagamento. Il socio sarà attivato automaticamente al pagamento.')
                ->with('payment_link', $link);
        }

        DB::transaction(function () use ($socio, $importo, $metodo, $tipo, $dataPagamento): void {
            if ($importo > 0) {
                Quota::create([
                    'socio_id' => $socio->id,
                    'associazione_id' => $socio->associazione_id,
                    'anno' => (int) now()->year,
                    'importo' => $importo,
                    'data_scadenza' => now()->endOfYear()->toDateString(),
                    'data_pagamento' => $dataPagamento,
                    'stato' => Quota::STATO_PAGATA,
                    'tipo' => $tipo,
                    'metodo_pagamento' => $metodo,
                ]);
            }

            // La data di iscrizione viene sovrascritta con oggi (comportamento legacy, spec §2.4)
            $socio->update([
                'stato' => Socio::STATO_ATTIVO,
                'data_iscrizione' => now()->toDateString(),
            ]);

            $this->activityLogger->log(
                $socio,
                SocioActivityLogger::TIPO_PAGAMENTO_CONFERMATO,
                sprintf('Pagamento confermato (€%s, %s) e socio attivato.', number_format($importo, 2, ',', '.'), $metodo)
            );

            $this->tessere->creaPerAnnoCorrente($socio);
        });

        $this->emailBenvenuto->accoda($socio);

        return back()->with('success', "Pagamento confermato: {$socio->nome_completo} è ora Attivo.");
    }

    /**
     * Rifiuto pre-iscrizione (spec §2.5): eliminazione definitiva,
     * ammessa solo negli stati pending.
     */
    public function rifiuta(Socio $socio): RedirectResponse
    {
        $this->authorize('delete', $socio);

        $statiPending = [Socio::STATO_IN_ATTESA, Socio::STATO_IN_ATTESA_DI_PAGAMENTO];

        if (! in_array($socio->stato, $statiPending, true)) {
            return back()->with('error', 'Socio non trovato o stato non valido.');
        }

        $nome = $socio->nome_completo;
        $socio->forceDelete(); // il rifiuto è una cancellazione definitiva (legacy)

        return back()->with('success', "Pre-iscrizione di {$nome} rifiutata ed eliminata.");
    }
}
