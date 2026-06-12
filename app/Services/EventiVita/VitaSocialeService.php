<?php

namespace App\Services\EventiVita;

use App\Models\Account;
use App\Models\Evento;
use App\Models\EventoPartecipante;
use App\Models\Prenotazione;
use App\Models\Socio;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Statistiche di partecipazione alla vita sociale (f.12 del piano):
 * tab "Vita sociale" nella scheda socio e top partecipanti in dashboard.
 *
 * Le presenze derivano da due canali complementari:
 * - prenotazioni dal frontend pubblico con check-in (account collegato al socio);
 * - partecipazioni registrate dall'admin (eventi_partecipanti "Confermato").
 */
class VitaSocialeService
{
    /**
     * Statistiche per la tab "Vita sociale" della scheda socio.
     *
     * @return array{
     *     account: Account|null,
     *     prenotazioni_totali: int,
     *     prenotazioni_con_checkin: int,
     *     tasso_partecipazione: int|null,
     *     ultima_presenza: Carbon|null,
     *     prenotazioni: Collection<int, Prenotazione>,
     *     partecipazioni_admin: Collection<int, EventoPartecipante>,
     * }
     */
    public function statisticheSocio(Socio $socio): array
    {
        /** @var Account|null $account */
        $account = Account::query()
            ->forAssociazione($socio->associazione_id)
            ->where('socio_id', $socio->id)
            ->first();

        $prenotazioni = $account === null
            ? collect()
            : Prenotazione::query()
                ->forAssociazione($socio->associazione_id)
                ->where('account_id', $account->id)
                ->whereIn('stato', [
                    Prenotazione::STATO_CONFERMATA,
                    Prenotazione::STATO_IN_ATTESA_PAGAMENTO,
                    Prenotazione::STATO_IN_APPROVAZIONE,
                    Prenotazione::STATO_LISTA_ATTESA,
                ])
                ->with(['evento' => fn ($q) => $q->withoutGlobalScope('associazione')])
                ->orderByDesc('created_at')
                ->get();

        // Solo gli eventi già svolti concorrono al tasso di partecipazione
        $confermateSvolte = $prenotazioni->filter(
            fn (Prenotazione $p) => $p->stato === Prenotazione::STATO_CONFERMATA
                && $p->evento?->data_evento?->isPast()
        );
        $conCheckin = $prenotazioni->filter(fn (Prenotazione $p) => $p->checkin_at !== null);

        $partecipazioniAdmin = EventoPartecipante::query()
            ->forAssociazione($socio->associazione_id)
            ->where('socio_id', $socio->id)
            ->where('stato_partecipazione', EventoPartecipante::STATO_CONFERMATO)
            ->with(['evento' => fn ($q) => $q->withoutGlobalScope('associazione')])
            ->get()
            ->sortByDesc(fn (EventoPartecipante $p) => $p->evento?->data_evento)
            ->values();

        $ultimaPresenza = collect([
            $conCheckin->max('checkin_at'),
            $partecipazioniAdmin
                ->map(fn (EventoPartecipante $p) => $p->evento?->data_evento)
                ->filter(fn ($data) => $data !== null && $data->isPast())
                ->max(),
        ])->filter()->max();

        return [
            'account' => $account,
            'prenotazioni_totali' => $prenotazioni->count(),
            'prenotazioni_con_checkin' => $conCheckin->count(),
            'tasso_partecipazione' => $confermateSvolte->isNotEmpty()
                ? (int) round($conCheckin->count() * 100 / $confermateSvolte->count())
                : null,
            'ultima_presenza' => $ultimaPresenza,
            'prenotazioni' => $prenotazioni->take(10)->values(),
            'partecipazioni_admin' => $partecipazioniAdmin->take(10),
        ];
    }

    /**
     * Top partecipanti del tenant per la dashboard: account con più
     * check-in agli eventi, con eventuale socio collegato.
     *
     * @return Collection<int, array{account: Account, presenze: int}>
     */
    public function topPartecipanti(string $associazioneId, int $limite = 5): Collection
    {
        $conteggi = Prenotazione::query()
            ->forAssociazione($associazioneId)
            ->whereNotNull('checkin_at')
            ->whereNotNull('account_id')
            ->selectRaw('account_id, COUNT(*) as presenze')
            ->groupBy('account_id')
            ->orderByDesc('presenze')
            ->limit($limite)
            ->pluck('presenze', 'account_id');

        if ($conteggi->isEmpty()) {
            return collect();
        }

        $accounts = Account::query()
            ->forAssociazione($associazioneId)
            ->with(['socio' => fn ($q) => $q->withoutGlobalScope('associazione')])
            ->whereIn('id', $conteggi->keys())
            ->get()
            ->keyBy('id');

        return $conteggi
            ->map(function (int $presenze, string $accountId) use ($accounts): ?array {
                $account = $accounts->get($accountId);

                return $account === null ? null : [
                    'account' => $account,
                    'presenze' => $presenze,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * L'account è abilitato a prenotare un evento riservato ai soci (f.10)?
     * Serve sia all'enforcement (PrenotazioneService) sia alla CTA pubblica.
     */
    public static function accountAbilitatoSoloSoci(Account $account): bool
    {
        $socio = $account->socio()
            ->withoutGlobalScope('associazione')
            ->first();

        return $socio !== null
            && $socio->associazione_id === $account->associazione_id
            && $socio->isAttivo();
    }

    /**
     * Gli eventi (qualunque tenant) raggruppati per giorno di un mese,
     * per le viste calendario (f.7).
     *
     * @param  Collection<int, Evento>  $eventi
     * @return Collection<string, Collection<int, Evento>>
     */
    public static function eventiPerGiorno(Collection $eventi): Collection
    {
        return $eventi->groupBy(fn (Evento $evento) => $evento->data_evento->format('Y-m-d'));
    }
}
