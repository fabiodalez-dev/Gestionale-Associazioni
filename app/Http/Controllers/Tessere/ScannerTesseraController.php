<?php

namespace App\Http\Controllers\Tessere;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Scanner QR standalone per la verifica tessere (spec §8.2).
 * Pagina pubblica e stateless: nessun DB, nessuna sessione; parla solo
 * con l'endpoint JSON di verifica. Asset html5-qrcode servito in locale.
 */
class ScannerTesseraController extends Controller
{
    public function __invoke(): View
    {
        return view('tessere.scanner');
    }
}
