<?php

namespace App\Http\Controllers\Soci;

use App\Http\Controllers\Controller;
use App\Models\Importazione;
use App\Models\Socio;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Storico delle importazioni (#55): elenco e report degli errori in CSV,
 * da correggere e reimportare. Visibile a chi può importare soci.
 */
class ImportazioneController extends Controller
{
    use AuthorizesRequests;

    public function index(): View
    {
        $this->authorize('create', Socio::class);

        return view('soci.importazioni', [
            'importazioni' => Importazione::query()->with('utente')->latest()->paginate(25),
        ]);
    }

    public function errori(string $importazione): StreamedResponse
    {
        $this->authorize('create', Socio::class);

        $trovata = Importazione::query()->findOrFail($importazione);

        return response()->streamDownload(function () use ($trovata): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['riga', 'errore'], ';', '"', '');
            foreach ($trovata->errori ?? [] as $riga) {
                foreach ($riga['errori'] as $errore) {
                    fputcsv($out, [$riga['riga'], $errore], ';', '"', '');
                }
            }
            fclose($out);
        }, 'errori-import-'.$trovata->created_at->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
