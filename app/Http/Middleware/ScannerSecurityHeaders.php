<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers per le pagine con scanner QR (scanner tessere, check-in):
 * come SecurityHeaders ma con camera consentita (self) e CSP che ammette
 * blob: per il flusso video di html5-qrcode (replica della CSP legacy).
 *
 * Le rotte che lo usano escludono SecurityHeaders e AddCspHeaders globali
 * (che bloccherebbero la fotocamera con camera=()).
 */
class ScannerSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $csp = "default-src 'self'; "
            ."style-src 'self' 'unsafe-inline'; "
            ."script-src 'self' 'unsafe-inline'; "
            ."img-src 'self' data: blob:; "
            ."media-src 'self' blob:; "
            ."connect-src 'self'; "
            ."font-src 'self' data:;";

        if ($request->isSecure()) {
            $csp = 'upgrade-insecure-requests; '.$csp;
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=(self)');
        $response->headers->set('Content-Security-Policy', $csp);

        return $response;
    }
}
