<?php

namespace App\Http\Controllers\Comunicazione;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comunicazione\MessaggioSocioRequest;
use App\Models\Associazione;
use App\Models\Socio;
use App\Models\StoricoAttivitaSocio;
use App\Services\Email\EmailLayout;
use App\Services\Email\EmailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

/**
 * Email diretta al socio dalla scheda (f.12 piano Gestione 2):
 * modale oggetto + corpo (testo semplice → HTML escapato), invio via
 * coda email del tenant con layout brandizzato e link disiscrizione,
 * tracciamento nello storico attività del socio. Rispetta l'opt-out.
 */
class MessaggioSocioController extends Controller
{
    /** Tipo riga nello storico attività del socio. */
    public const TIPO_ATTIVITA = 'email_diretta';

    public function __construct(
        private readonly EmailService $emailService,
        private readonly EmailLayout $layout,
    ) {}

    public function store(MessaggioSocioRequest $request, Socio $socio): RedirectResponse
    {
        $associazione = Associazione::query()->findOrFail(session('associazione_id'));

        $ritorno = redirect()->route('soci.show', $socio);

        if (! $this->emailService->isConfigured($associazione)) {
            return $ritorno->with('danger', 'SMTP non configurato. Configura le impostazioni email prima di inviare.');
        }

        if (blank($socio->email)) {
            return $ritorno->with('warning', 'Il socio non ha un indirizzo email.');
        }

        if ($socio->email_opt_out) {
            return $ritorno->with('warning', 'Il socio ha disattivato le comunicazioni email (opt-out).');
        }

        $data = $request->validated();

        // Corpo in testo semplice: escapato e con a-capo → <br> (niente HTML libero)
        $corpoHtml = '<p>'.nl2br(e($data['corpo'])).'</p>';

        $this->emailService->queueEmail(
            $associazione->id,
            $socio->email,
            trim($socio->nome.' '.$socio->cognome),
            $data['oggetto'],
            $this->layout->wrap($corpoHtml, $associazione, $socio),
            $socio->id,
            null,
            self::TIPO_ATTIVITA,
            3 // priorità più alta delle comunicazioni massive (default 5)
        );

        // Tracciamento nello storico attività (fail-safe: non blocca l'invio)
        try {
            StoricoAttivitaSocio::create([
                'associazione_id' => $associazione->id,
                'socio_id' => $socio->id,
                'utente_id' => $request->user()?->id,
                'tipo_attivita' => self::TIPO_ATTIVITA,
                'descrizione' => 'Email inviata: '.Str::limit($data['oggetto'], 200),
                'dettagli_json' => ['oggetto' => $data['oggetto']],
                'data_attivita' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }

        return $ritorno->with('success', 'Email accodata: verrà inviata dalla coda di invio.');
    }
}
