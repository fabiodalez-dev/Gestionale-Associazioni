<?php

namespace Tests\Feature\Prenotazioni;

use App\Models\Prenotazione;
use App\Services\Prenotazioni\PrenotazioneService;
use Illuminate\Support\Facades\DB;

/**
 * Spia di test: registra le invocazioni degli hook notifica*() (vuoti nel
 * service, collegati alle Notification nella fase Rifiniture) e il livello
 * di transazione al momento dell'invocazione, per verificare che le
 * notifiche partano SEMPRE fuori dalla transazione di dominio.
 */
class PrenotazioneServiceSpia extends PrenotazioneService
{
    /** @var list<array{0: string, 1: string}> [tipo, prenotazione_id] */
    public array $notifiche = [];

    /** @var list<int> */
    public array $livelliTransazione = [];

    protected function notificaConfermata(Prenotazione $prenotazione): void
    {
        $this->registra('confermata', $prenotazione);
    }

    protected function notificaInAttesaPagamento(Prenotazione $prenotazione): void
    {
        $this->registra('in_attesa_pagamento', $prenotazione);
    }

    protected function notificaInListaAttesa(Prenotazione $prenotazione): void
    {
        $this->registra('lista_attesa', $prenotazione);
    }

    protected function notificaPromozioneListaAttesa(Prenotazione $prenotazione): void
    {
        $this->registra('promozione', $prenotazione);
    }

    protected function notificaPagamentoScaduto(Prenotazione $prenotazione): void
    {
        $this->registra('pagamento_scaduto', $prenotazione);
    }

    protected function notificaAnnullata(Prenotazione $prenotazione): void
    {
        $this->registra('annullata', $prenotazione);
    }

    protected function notificaPromemoriaEvento(Prenotazione $prenotazione): void
    {
        $this->registra('promemoria', $prenotazione);
    }

    /**
     * @return list<string> i tipi di notifica inviati, in ordine
     */
    public function tipiInviati(): array
    {
        return array_column($this->notifiche, 0);
    }

    /**
     * @return list<string> gli id prenotazione notificati per il tipo dato
     */
    public function idPerTipo(string $tipo): array
    {
        return array_values(array_column(
            array_filter($this->notifiche, fn (array $n) => $n[0] === $tipo),
            1
        ));
    }

    protected function registra(string $tipo, Prenotazione $prenotazione): void
    {
        $this->notifiche[] = [$tipo, $prenotazione->id];
        $this->livelliTransazione[] = DB::transactionLevel();
    }
}
