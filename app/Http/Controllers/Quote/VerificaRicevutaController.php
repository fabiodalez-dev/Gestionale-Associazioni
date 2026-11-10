<?php

namespace App\Http\Controllers\Quote;

use App\Http\Controllers\Controller;
use App\Models\Ricevuta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Verifica pubblica di una ricevuta (#77) dal QR stampato sul PDF: l'URL è
 * firmato (come quello delle tessere), quindi non si indovina; la pagina
 * mostra solo ciò che serve a riconoscerla, con l'intestatario abbreviato.
 * Fuori dai motori di ricerca (IndicizzazioneMotori).
 */
class VerificaRicevutaController extends Controller
{
    public function __invoke(Request $request, string $ricevuta): View|JsonResponse
    {
        $trovata = Ricevuta::allTenants()->with('associazione')->find($ricevuta);

        $dati = $trovata === null ? null : [
            'valida' => true,
            'associazione' => $trovata->associazione?->nome,
            'numero' => $trovata->numero.'/'.$trovata->anno,
            'emessa_il' => $trovata->emessa_at->toDateString(),
            'importo' => number_format((float) $trovata->importo, 2, '.', ''),
            'intestatario' => $this->abbreviato($trovata),
        ];

        if ($request->wantsJson()) {
            return $dati === null
                ? response()->json(['valida' => false], 404)
                : response()->json($dati);
        }

        return view('quote.verifica-ricevuta', ['dati' => $dati]);
    }

    /** "Mario R." dall'istantanea dell'emissione: riconoscibile, non esposto. */
    private function abbreviato(Ricevuta $ricevuta): ?string
    {
        $nome = trim((string) $ricevuta->intestatario_nome);
        $cognome = trim((string) $ricevuta->intestatario_cognome);

        if ($nome === '' && $cognome === '') {
            return null;
        }

        return trim($nome.' '.($cognome !== '' ? mb_substr($cognome, 0, 1).'.' : ''));
    }
}
