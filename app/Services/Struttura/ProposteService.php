<?php

namespace App\Services\Struttura;

use App\Models\AdesioneProposta;
use App\Models\Associazione;
use App\Models\Proposta;
use App\Models\Riunione;
use App\Models\Socio;
use App\Models\Utente;
use App\Models\VotazioneRiunione;
use App\Notifications\Struttura\PropostaNotification;
use App\Settings\PartecipazioneSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Proposte dei soci (issue #10).
 *
 * - Il socio attivo presenta la proposta; con la moderazione attiva resta in
 *   revisione finché la segreteria non la pubblica o non la ammette.
 * - Il proponente conta come prima adesione; gli altri soci attivi aderiscono
 *   una volta (revocabile) finché la proposta non entra in un ordine del giorno.
 * - Alla soglia (numero o percentuale dei soci attivi) la proposta passa a
 *   "da esaminare" e la segreteria viene avvisata.
 * - Inserita nell'OdG di una riunione, prende l'esito della votazione del suo
 *   punto (approvato = accolta, respinto = respinta) o quello registrato a mano.
 *
 * A ogni cambio di stato il proponente viene avvisato; dall'ingresso
 * nell'OdG in poi anche gli aderenti senza opt-out.
 */
class ProposteService
{
    /**
     * @param  array{titolo: string, testo: string, categoria: string, destinatario: string}  $dati
     */
    public function presenta(Socio $socio, array $dati): Proposta
    {
        if (! $socio->isAttivo()) {
            throw new PropostaNonValidaException('Solo i soci attivi possono presentare proposte.');
        }

        $moderata = $socio->associazione->impostazioni(PartecipazioneSettings::class)->proposte_moderazione;

        $proposta = DB::transaction(function () use ($socio, $dati, $moderata): Proposta {
            $proposta = Proposta::create($dati + [
                'associazione_id' => $socio->associazione_id,
                'socio_id' => $socio->id,
                'stato' => $moderata ? Proposta::STATO_IN_REVISIONE : Proposta::STATO_PUBBLICATA,
                'pubblicata_at' => $moderata ? null : now(),
            ]);

            AdesioneProposta::create([
                'associazione_id' => $socio->associazione_id,
                'proposta_id' => $proposta->id,
                'socio_id' => $socio->id,
            ]);

            return $proposta;
        });

        if (! $moderata) {
            $this->verificaSoglia($proposta);
        }

        return $proposta;
    }

    public function pubblica(Proposta $proposta): void
    {
        $this->richiediStato($proposta, [Proposta::STATO_IN_REVISIONE]);

        $proposta->update(['stato' => Proposta::STATO_PUBBLICATA, 'pubblicata_at' => now()]);
        $this->avvisa($proposta, soloProponente: true);
        $this->verificaSoglia($proposta);
    }

    public function nonAmmettere(Proposta $proposta, string $motivazione): void
    {
        $this->richiediStato($proposta, [Proposta::STATO_IN_REVISIONE]);

        $proposta->update(['stato' => Proposta::STATO_NON_AMMESSA, 'motivazione' => $motivazione]);
        $this->avvisa($proposta, soloProponente: true);
    }

    public function aderisci(Proposta $proposta, Socio $socio): void
    {
        if (! $proposta->accettaAdesioni()) {
            throw new PropostaNonValidaException('La proposta non raccoglie più adesioni.');
        }

        if (! $socio->isAttivo()) {
            throw new PropostaNonValidaException('Solo i soci attivi possono aderire.');
        }

        try {
            AdesioneProposta::create([
                'associazione_id' => $proposta->associazione_id,
                'proposta_id' => $proposta->id,
                'socio_id' => $socio->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new PropostaNonValidaException('Hai già aderito a questa proposta.');
        }

        $this->verificaSoglia($proposta);
    }

    public function revocaAdesione(Proposta $proposta, Socio $socio): void
    {
        if (! $proposta->accettaAdesioni()) {
            throw new PropostaNonValidaException('La proposta è già all\'esame: l\'adesione non si revoca più.');
        }

        if ($proposta->socio_id === $socio->id) {
            throw new PropostaNonValidaException('Il proponente non può revocare la propria adesione.');
        }

        AdesioneProposta::query()->where('proposta_id', $proposta->id)->where('socio_id', $socio->id)->delete();
    }

    /**
     * Adesioni richieste per l'associazione.
     */
    public function soglia(Associazione $associazione): int
    {
        $impostazioni = $associazione->impostazioni(PartecipazioneSettings::class);

        if (! $impostazioni->proposte_soglia_percentuale) {
            return $impostazioni->proposte_soglia;
        }

        $attivi = Socio::query()
            ->withoutGlobalScopes()
            ->where('associazione_id', $associazione->id)
            ->where('stato', Socio::STATO_ATTIVO)
            ->count();

        return max(1, (int) ceil($attivi * min($impostazioni->proposte_soglia, 100) / 100));
    }

    /**
     * Aggiunge la proposta come punto dell'ordine del giorno di una riunione
     * pianificata dell'organo destinatario.
     */
    public function inserisciInOdg(Proposta $proposta, Riunione $riunione): void
    {
        $this->richiediStato($proposta, [Proposta::STATO_PUBBLICATA, Proposta::STATO_DA_ESAMINARE]);

        if ($riunione->stato !== Riunione::STATO_PIANIFICATA) {
            throw new PropostaNonValidaException('La proposta si inserisce in una riunione non ancora svolta.');
        }

        if (($proposta->destinatario === 'assemblea') !== $riunione->isAssemblea()) {
            throw new PropostaNonValidaException('La riunione scelta non è dell\'organo a cui è rivolta la proposta.');
        }

        DB::transaction(function () use ($proposta, $riunione): void {
            $riunione = Riunione::query()->whereKey($riunione->id)->lockForUpdate()->firstOrFail();
            $odg = is_array($riunione->odg) ? $riunione->odg : [];
            $odg[] = [
                'punto' => $proposta->titolo,
                'descrizione' => 'Proposta dei soci presentata da '.$proposta->proponente?->nome_completo,
                'proposta_id' => $proposta->id,
            ];
            $riunione->update(['odg' => $odg]);

            $proposta->update([
                'stato' => Proposta::STATO_IN_ODG,
                'riunione_id' => $riunione->id,
                'punto_indice' => count($odg) - 1,
            ]);
        });

        $this->avvisa($proposta);
    }

    /**
     * Esito registrato dalla segreteria (es. decisione del direttivo senza votazione).
     */
    public function registraEsito(Proposta $proposta, bool $accolta, ?string $motivazione): void
    {
        $this->richiediStato($proposta, [Proposta::STATO_PUBBLICATA, Proposta::STATO_DA_ESAMINARE, Proposta::STATO_IN_ODG]);

        $this->chiudi($proposta, $accolta, $motivazione);
    }

    /**
     * Aggiorna la proposta legata al punto votato (chiamata quando si
     * registra o si chiude una votazione).
     */
    public function daVotazione(VotazioneRiunione $votazione): void
    {
        if ($votazione->esito === VotazioneRiunione::ESITO_RINVIATO) {
            return;
        }

        $proposta = Proposta::query()
            ->where('riunione_id', $votazione->riunione_id)
            ->where('punto_indice', $votazione->punto_indice)
            ->where('stato', Proposta::STATO_IN_ODG)
            ->first();

        if ($proposta !== null) {
            $this->chiudi($proposta, $votazione->isApprovata(), null);
        }
    }

    /**
     * L'indice del punto della proposta cambia se l'OdG viene riordinato:
     * si riallinea dal proposta_id salvato nel punto.
     */
    public function riallineaOdg(Riunione $riunione): void
    {
        $odg = is_array($riunione->odg) ? $riunione->odg : [];
        $presenti = [];

        foreach ($odg as $indice => $punto) {
            $id = is_array($punto) ? ($punto['proposta_id'] ?? null) : null;

            if ($id !== null) {
                $presenti[] = $id;
                Proposta::query()->whereKey($id)->where('riunione_id', $riunione->id)->update(['punto_indice' => $indice]);
            }
        }

        // Punto tolto dall'OdG: la proposta torna in attesa di esame
        Proposta::query()
            ->where('riunione_id', $riunione->id)
            ->where('stato', Proposta::STATO_IN_ODG)
            ->whereNotIn('id', $presenti)
            ->update(['stato' => Proposta::STATO_DA_ESAMINARE, 'riunione_id' => null, 'punto_indice' => null]);
    }

    private function chiudi(Proposta $proposta, bool $accolta, ?string $motivazione): void
    {
        $proposta->update([
            'stato' => $accolta ? Proposta::STATO_ACCOLTA : Proposta::STATO_RESPINTA,
            'motivazione' => $motivazione ?? $proposta->motivazione,
            'esito_at' => now(),
        ]);

        $this->avvisa($proposta);
    }

    private function verificaSoglia(Proposta $proposta): void
    {
        if ($proposta->stato !== Proposta::STATO_PUBBLICATA) {
            return;
        }

        $adesioni = AdesioneProposta::query()->where('proposta_id', $proposta->id)->count();

        if ($adesioni < $this->soglia($proposta->associazione)) {
            return;
        }

        $aggiornate = Proposta::query()
            ->whereKey($proposta->id)
            ->where('stato', Proposta::STATO_PUBBLICATA)
            ->update(['stato' => Proposta::STATO_DA_ESAMINARE, 'soglia_raggiunta_at' => now()]);

        // Solo chi ha fatto scattare la soglia avvisa (niente doppi invii)
        if ($aggiornate === 1) {
            $proposta->refresh();
            $this->avvisa($proposta, soloProponente: true);

            $segreteria = Utente::query()
                ->where('associazione_id', $proposta->associazione_id)
                ->where('ruolo', Utente::RUOLO_ADMIN_ASSOCIAZIONE)
                ->where('attivo', true)
                ->get();

            Notification::send($segreteria, new PropostaNotification($proposta));
        }
    }

    private function avvisa(Proposta $proposta, bool $soloProponente = false): void
    {
        $proposta->loadMissing('proponente');
        $destinatari = collect([$proposta->proponente])->filter();

        if (! $soloProponente) {
            $aderenti = Socio::query()
                ->withoutGlobalScope('sede')
                ->whereIn('id', AdesioneProposta::query()->where('proposta_id', $proposta->id)->select('socio_id'))
                ->where('id', '!=', $proposta->socio_id)
                ->where('email_opt_out', false)
                ->get();
            $destinatari = $destinatari->merge($aderenti);
        }

        Notification::send($destinatari->filter(fn (Socio $s) => filled($s->email)), new PropostaNotification($proposta));
    }

    /**
     * @param  list<string>  $stati
     */
    private function richiediStato(Proposta $proposta, array $stati): void
    {
        if (! in_array($proposta->stato, $stati, true)) {
            throw new PropostaNonValidaException("Operazione non disponibile: la proposta è \"{$proposta->statoLabel()}\".");
        }
    }
}
