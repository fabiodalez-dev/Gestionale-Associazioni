<?php

namespace App\Http\Controllers\Eventi;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Pagina mobile standalone di check-in con scanner QR.
 *
 * Nessuna autenticazione di sessione: la pagina dialoga esclusivamente con
 * l'API REST /api/v1 tramite API key Bearer (permesso eventi:write).
 *
 * Header di sicurezza dedicati (impostati qui e NON dal middleware globale
 * SecurityHeaders, escluso sulla rotta): la pagina richiede
 * Permissions-Policy: camera=(self) per lo scanner QR. Rispetto al legacy
 * la CSP è irrigidita: html5-qrcode è servita come asset locale, quindi
 * nessuna sorgente CDN esterna è ammessa.
 */
class CheckinPageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $csp = "default-src 'self'; "
            ."style-src 'self' 'unsafe-inline'; "
            ."script-src 'self' 'unsafe-inline'; "
            ."img-src 'self' data: blob:; "
            ."media-src 'self' blob:; "
            ."connect-src 'self'; "
            ."font-src 'self';";

        if ($request->isSecure() || $request->header('X-Forwarded-Proto') === 'https') {
            $csp = 'upgrade-insecure-requests; '.$csp;
        }

        return response()
            ->view('eventi.checkin', [
                'apiBaseUrl' => url('/api/v1'),
            ])
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('X-Frame-Options', 'SAMEORIGIN')
            ->header('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->header('Permissions-Policy', 'camera=(self)')
            ->header('Content-Security-Policy', $csp);
    }
}
