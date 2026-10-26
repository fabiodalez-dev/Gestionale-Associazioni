<?php

namespace App\Services\Comunicazione;

use App\Models\Discussione;
use App\Models\DiscussioneIscrizione;
use App\Models\DiscussioneMessaggio;
use App\Models\DiscussioneProfilo;
use App\Models\DiscussioneSegnalazione;
use App\Models\DiscussioneSpazio;
use App\Models\Socio;
use App\Models\Utente;
use App\Notifications\Comunicazione\RiepilogoDiscussioniNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Spazio di discussione tra soci (issue #13).
 *
 * - Leggono e scrivono i soci attivi con accesso allo spazio; per scrivere
 *   serve aver accettato il regolamento e non essere sospesi.
 * - Chi apre o risponde a una discussione la segue e riceve il riepilogo
 *   giornaliero dei nuovi messaggi (opt-out email rispettato).
 * - La moderazione nasconde e ripristina messaggi, chiude o fissa
 *   discussioni, sospende e riattiva i soci; le segnalazioni dei soci
 *   arrivano alla moderazione.
 */
class DiscussioniService
{
    public function profilo(Socio $socio): DiscussioneProfilo
    {
        return DiscussioneProfilo::query()->firstOrCreate(
            ['socio_id' => $socio->id],
            ['associazione_id' => $socio->associazione_id],
        );
    }

    public function accettaRegolamento(Socio $socio): void
    {
        $profilo = $this->profilo($socio);

        if ($profilo->regolamento_accettato_at === null) {
            $profilo->update(['regolamento_accettato_at' => now()]);
        }
    }

    /**
     * Perché il socio non può scrivere, oppure null se può.
     */
    public function motivoBloccoScrittura(Socio $socio): ?string
    {
        $profilo = $this->profilo($socio);

        return match (true) {
            ! $socio->isAttivo() => 'Solo i soci attivi possono scrivere.',
            $profilo->sospeso_at !== null => 'La moderazione ha sospeso la tua possibilità di scrivere'.(filled($profilo->sospeso_motivo) ? ": {$profilo->sospeso_motivo}" : '.'),
            $profilo->regolamento_accettato_at === null => 'Per scrivere accetta prima il regolamento.',
            default => null,
        };
    }

    public function apri(DiscussioneSpazio $spazio, Socio $socio, string $titolo, string $testo): Discussione
    {
        $this->verificaAccesso($spazio, $socio);
        $this->verificaScrittura($socio);

        return DB::transaction(function () use ($spazio, $socio, $titolo, $testo): Discussione {
            $discussione = Discussione::create([
                'associazione_id' => $spazio->associazione_id,
                'spazio_id' => $spazio->id,
                'socio_id' => $socio->id,
                'titolo' => trim($titolo),
                'ultima_attivita_at' => now(),
            ]);

            $this->creaMessaggio($discussione, $socio, $testo);
            $this->segui($discussione, $socio);

            return $discussione;
        });
    }

    public function rispondi(Discussione $discussione, Socio $socio, string $testo): DiscussioneMessaggio
    {
        $this->verificaAccesso($discussione->spazio, $socio);
        $this->verificaScrittura($socio);

        if ($discussione->chiusa) {
            throw new DiscussioneNonValidaException('La discussione è chiusa: non si possono aggiungere risposte.');
        }

        return DB::transaction(function () use ($discussione, $socio, $testo): DiscussioneMessaggio {
            $messaggio = $this->creaMessaggio($discussione, $socio, $testo);
            $discussione->update(['ultima_attivita_at' => now()]);
            $this->segui($discussione, $socio);

            return $messaggio;
        });
    }

    public function segnala(DiscussioneMessaggio $messaggio, Socio $socio, ?string $motivo): void
    {
        $this->verificaAccesso($messaggio->discussione->spazio, $socio);

        if ($messaggio->socio_id === $socio->id) {
            throw new DiscussioneNonValidaException('Non puoi segnalare un tuo messaggio.');
        }

        try {
            DiscussioneSegnalazione::create([
                'associazione_id' => $messaggio->associazione_id,
                'messaggio_id' => $messaggio->id,
                'socio_id' => $socio->id,
                'motivo' => filled($motivo) ? mb_substr(trim((string) $motivo), 0, 500) : null,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new DiscussioneNonValidaException('Hai già segnalato questo messaggio.');
        }
    }

    public function segui(Discussione $discussione, Socio $socio): void
    {
        DiscussioneIscrizione::query()->firstOrCreate(
            ['discussione_id' => $discussione->id, 'socio_id' => $socio->id],
            ['associazione_id' => $discussione->associazione_id, 'ultimo_riepilogo_at' => now()],
        );
    }

    public function smettiDiSeguire(Discussione $discussione, Socio $socio): void
    {
        DiscussioneIscrizione::query()->where('discussione_id', $discussione->id)->where('socio_id', $socio->id)->delete();
    }

    public function nascondi(DiscussioneMessaggio $messaggio, Utente $moderatore): void
    {
        $messaggio->update(['nascosto_at' => now(), 'nascosto_da' => $moderatore->id]);
        $this->chiudiSegnalazioni($messaggio);
    }

    public function ripristina(DiscussioneMessaggio $messaggio): void
    {
        $messaggio->update(['nascosto_at' => null, 'nascosto_da' => null]);
    }

    /** La segnalazione è stata valutata senza nascondere il messaggio. */
    public function chiudiSegnalazioni(DiscussioneMessaggio $messaggio): void
    {
        DiscussioneSegnalazione::query()->where('messaggio_id', $messaggio->id)->whereNull('gestita_at')->update(['gestita_at' => now()]);
    }

    public function sospendi(Socio $socio, ?string $motivo): void
    {
        $this->profilo($socio)->update(['sospeso_at' => now(), 'sospeso_motivo' => filled($motivo) ? mb_substr(trim((string) $motivo), 0, 500) : null]);
    }

    public function riattiva(Socio $socio): void
    {
        $this->profilo($socio)->update(['sospeso_at' => null, 'sospeso_motivo' => null]);
    }

    /**
     * Riepilogo dei nuovi messaggi delle discussioni seguite: una email per
     * socio con le discussioni che hanno messaggi altrui non nascosti dopo
     * l'ultimo riepilogo. Restituisce quante email sono partite.
     */
    public function inviaRiepiloghi(): int
    {
        $inviate = 0;
        $ora = now();

        DiscussioneIscrizione::query()
            ->withoutGlobalScopes()
            ->with([
                'socio' => fn ($q) => $q->withoutGlobalScopes(),
                'discussione' => fn ($q) => $q->withoutGlobalScopes(),
                'discussione.spazio' => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->get()
            ->groupBy('socio_id')
            ->each(function ($iscrizioni) use ($ora, &$inviate): void {
                /** @var Socio|null $socio */
                $socio = $iscrizioni->first()->socio;

                if ($socio === null || $socio->trashed() || ! $socio->isAttivo() || blank($socio->email) || $socio->email_opt_out) {
                    return;
                }

                $novita = $iscrizioni
                    ->filter(fn (DiscussioneIscrizione $i) => $i->discussione !== null && $i->discussione->spazio?->accessibileA($socio))
                    ->map(fn (DiscussioneIscrizione $i) => [
                        'discussione' => $i->discussione,
                        'nuovi' => DiscussioneMessaggio::query()
                            ->withoutGlobalScopes()
                            ->where('discussione_id', $i->discussione_id)
                            ->where('socio_id', '!=', $socio->id)
                            ->whereNull('nascosto_at')
                            ->where('created_at', '>', $i->ultimo_riepilogo_at ?? $i->created_at)
                            ->where('created_at', '<=', $ora)
                            ->count(),
                    ])
                    ->filter(fn (array $riga) => $riga['nuovi'] > 0)
                    ->values();

                if ($novita->isEmpty()) {
                    return;
                }

                // Solo dati semplici nella notifica in coda
                $socio->notify(new RiepilogoDiscussioniNotification($novita->map(fn (array $riga) => [
                    'titolo' => $riga['discussione']->titolo,
                    'spazio' => $riga['discussione']->spazio?->nome,
                    'nuovi' => $riga['nuovi'],
                    'url' => route('area-soci.discussioni.show', $riga['discussione']->id),
                ])->all()));
                DiscussioneIscrizione::query()->withoutGlobalScopes()->whereKey($iscrizioni->pluck('id'))->update(['ultimo_riepilogo_at' => $ora]);
                $inviate++;
            });

        return $inviate;
    }

    private function creaMessaggio(Discussione $discussione, Socio $socio, string $testo): DiscussioneMessaggio
    {
        return DiscussioneMessaggio::create([
            'associazione_id' => $discussione->associazione_id,
            'discussione_id' => $discussione->id,
            'socio_id' => $socio->id,
            'testo' => trim($testo),
        ]);
    }

    private function verificaAccesso(DiscussioneSpazio $spazio, Socio $socio): void
    {
        if (! $spazio->accessibileA($socio)) {
            throw new DiscussioneNonValidaException('Non hai accesso a questo spazio di discussione.');
        }
    }

    private function verificaScrittura(Socio $socio): void
    {
        $motivo = $this->motivoBloccoScrittura($socio);

        if ($motivo !== null) {
            throw new DiscussioneNonValidaException($motivo);
        }
    }
}
