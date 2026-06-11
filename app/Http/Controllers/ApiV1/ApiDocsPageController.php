<?php

namespace App\Http\Controllers\ApiV1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pagina interna "Documentazione API" (parità legacy pages/api_docs.php §3):
 * guida statica con autenticazione, permessi, codici errore, esempi client e
 * contratto del QR code. La reference interattiva OpenAPI è su /docs/api
 * (Scramble, miglioramento 22).
 */
class ApiDocsPageController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('api-v1.api-docs', [
            'baseUrl' => rtrim(url('/api/v1'), '/'),
        ]);
    }
}
