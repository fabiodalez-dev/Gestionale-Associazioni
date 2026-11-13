<?php

namespace Tests\Concerns;

use App\Models\Associazione;
use App\Models\IscrizioneDaConfermare;
use App\Services\Iscrizioni\FormIscrizioneService;
use Illuminate\Testing\TestResponse;

/**
 * Iscrizione da ospite con conferma dell'email: il modulo pubblico mette la
 * richiesta in sospeso e manda un link firmato; socio e richiesta nascono
 * solo al clic. Questi helper fanno quello che farebbe la persona aprendo
 * l'email, senza dipendere da come i singoli test intercettano le notifiche.
 */
trait ConfermaIscrizioneOspite
{
    /**
     * Invia il modulo /a/{slug}/iscriviti da ospite e, se è partita una
     * conferma per quell'email, apre il link. Ritorna la risposta del clic
     * (o quella dell'invio, se la conferma non è partita: errori di
     * validazione o risposta neutra).
     *
     * @param  array<string, mixed>  $dati
     */
    protected function inviaEConfermaIscrizioneOspite(string $url, array $dati): TestResponse
    {
        $prima = IscrizioneDaConfermare::query()->withoutGlobalScopes()->pluck('id')->all();
        $invio = $this->post($url, $dati);

        $nuova = IscrizioneDaConfermare::query()->withoutGlobalScopes()
            ->whereNotIn('id', $prima)
            ->where('email', (string) ($dati['email'] ?? ''))
            ->first();

        return $nuova !== null ? $this->apriLinkConferma($nuova) : $invio;
    }

    /**
     * Clic sul link di conferma dell'ultima iscrizione in sospeso per l'email.
     */
    protected function confermaIscrizioneOspite(string $email): TestResponse
    {
        $pendente = IscrizioneDaConfermare::query()->withoutGlobalScopes()->where('email', $email)->latest()->firstOrFail();

        return $this->apriLinkConferma($pendente);
    }

    private function apriLinkConferma(IscrizioneDaConfermare $pendente): TestResponse
    {
        $associazione = Associazione::query()->withoutGlobalScopes()->findOrFail($pendente->associazione_id);

        return $this->get(app(FormIscrizioneService::class)->linkConferma($pendente, $associazione));
    }
}
