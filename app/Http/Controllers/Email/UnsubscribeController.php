<?php

namespace App\Http\Controllers\Email;

use App\Http\Controllers\Controller;
use App\Models\Associazione;
use App\Models\Socio;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Disiscrizione pubblica (nessuna autenticazione, protetta dal token per socio).
 *
 * Double opt-out: GET mostra la conferma, il POST esegue la disiscrizione
 * (evita disiscrizioni da prefetch dei link). Idempotente se già disiscritto.
 */
class UnsubscribeController extends Controller
{
    public function show(string $socioId, string $token): Response
    {
        $socio = $this->findSocio($socioId, $token);

        if ($socio === null) {
            return $this->page('invalid', null, 404);
        }

        if ($socio->email_opt_out) {
            return $this->page('already', $socio);
        }

        return $this->page('confirm', $socio);
    }

    public function confirm(Request $request, string $socioId, string $token): Response
    {
        $socio = $this->findSocio($socioId, $token);

        if ($socio === null) {
            return $this->page('invalid', null, 404);
        }

        if ($socio->email_opt_out) {
            return $this->page('already', $socio);
        }

        if (! $request->boolean('confirm_unsubscribe')) {
            return $this->page('confirm', $socio);
        }

        $socio->forceFill(['email_opt_out' => true])->saveQuietly();

        return $this->page('done', $socio);
    }

    /**
     * Lookup pubblico fuori da ogni scope tenant, con confronto
     * constant-time del token (il legacy non lo faceva).
     */
    private function findSocio(string $socioId, string $token): ?Socio
    {
        if ($socioId === '' || $token === '' || strlen($token) > 128) {
            return null;
        }

        $socio = Socio::withoutGlobalScopes()
            ->with(['associazione' => fn ($q) => $q->withoutGlobalScopes()])
            ->whereKey($socioId)
            ->first();

        if ($socio === null || empty($socio->email_opt_out_token)) {
            return null;
        }

        return hash_equals($socio->email_opt_out_token, $token) ? $socio : null;
    }

    private function page(string $state, ?Socio $socio, int $status = 200): Response
    {
        $associazione = $socio?->associazione;

        return response()->view('email.unsubscribe', [
            'state' => $state,
            'socio' => $socio,
            'associazioneNome' => $associazione instanceof Associazione
                ? $associazione->nome
                : 'Gestione Associazioni',
        ], $status);
    }
}
