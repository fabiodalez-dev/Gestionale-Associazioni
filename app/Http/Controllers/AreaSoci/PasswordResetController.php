<?php

namespace App\Http\Controllers\AreaSoci;

use App\Http\Controllers\Controller;
use App\Http\Requests\AreaSoci\RichiestaResetPasswordRequest;
use App\Mail\AreaSoci\ImpostaPasswordSocioMail;
use App\Models\Socio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Richiesta del link "imposta password" (primo accesso o reset).
 * Stesso flusso del legacy (token 64 hex, TTL 1 ora, monouso) con i fix:
 * email reale via Mailable, risposta neutra (niente user-enumeration),
 * URL https da APP_URL.
 */
class PasswordResetController extends Controller
{
    public function sendLink(RichiestaResetPasswordRequest $request): RedirectResponse
    {
        /** @var Socio|null $socio */
        $socio = Socio::allTenants()
            ->where('email', $request->string('email')->toString())
            ->first();

        if ($socio !== null) {
            $token = bin2hex(random_bytes(32));

            // Un solo token valido per socio: sovrascrive l'eventuale precedente
            $socio->forceFill([
                'password_reset_token' => $token,
                'password_reset_expires' => now()->addHour(),
            ])->save();

            try {
                Mail::to($socio->email)->send(
                    new ImpostaPasswordSocioMail($socio, route('area-soci.set-password', ['token' => $token]))
                );
            } catch (Throwable $e) {
                report($e);
            }
        }

        // Risposta identica per email esistente e non (niente enumeration)
        return back()->with(
            'status',
            'Se l\'indirizzo email è registrato, riceverai un messaggio con il link per impostare la password.'
        );
    }
}
