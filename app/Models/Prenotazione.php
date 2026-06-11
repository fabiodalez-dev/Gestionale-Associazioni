<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Services\Pagamenti\Pagabile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prenotazione di un evento dal frontend pubblico.
 *
 * Macchina a stati:
 * - creazione → confermata (gratuito, posti ok) | in_attesa_pagamento
 *   (a pagamento, posti riservati, scadenza +30') | lista_attesa (pieno);
 * - in_attesa_pagamento → confermata (webhook) | scaduta (scheduler) | annullata;
 * - confermata → annullata (utente entro la finestra, admin sempre).
 * Il check-in NON è uno stato: è checkin_at su una confermata.
 *
 * I posti occupati di un evento sono SEMPRE SUM(posti) sulle prenotazioni
 * attive (nessun contatore denormalizzato); le scritture concorrenti vanno
 * serializzate con lockForUpdate() sulla riga eventi (PrenotazioneService).
 */
class Prenotazione extends Model implements Pagabile
{
    use BelongsToAssociazione;
    use HasFactory;
    use HasUuids;

    public const STATO_IN_ATTESA_PAGAMENTO = 'in_attesa_pagamento';

    public const STATO_CONFERMATA = 'confermata';

    public const STATO_LISTA_ATTESA = 'lista_attesa';

    public const STATO_ANNULLATA = 'annullata';

    public const STATO_SCADUTA = 'scaduta';

    public const STATI = [
        self::STATO_IN_ATTESA_PAGAMENTO,
        self::STATO_CONFERMATA,
        self::STATO_LISTA_ATTESA,
        self::STATO_ANNULLATA,
        self::STATO_SCADUTA,
    ];

    /** Stati che consumano posti (riservati o confermati). */
    public const STATI_ATTIVI = [
        self::STATO_CONFERMATA,
        self::STATO_IN_ATTESA_PAGAMENTO,
    ];

    /** Alfabeto base32 non ambiguo (niente 0/1/I/L/O/U) per i codici prenotazione. */
    public const ALFABETO_CODICE = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    protected $table = 'prenotazioni';

    protected $fillable = [
        'associazione_id',
        'evento_id',
        'account_id',
        'codice',
        'qr_token',
        'stato',
        'posti',
        'prezzo_unitario',
        'importo_totale',
        'gateway',
        'gateway_transaction_id',
        'pagata_at',
        'scadenza_pagamento_at',
        'promossa_at',
        'annullata_at',
        'motivo_annullamento',
        'checkin_at',
        'checkin_utente_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'posti' => 'integer',
            'prezzo_unitario' => 'decimal:2',
            'importo_totale' => 'decimal:2',
            'pagata_at' => 'datetime',
            'scadenza_pagamento_at' => 'datetime',
            'promossa_at' => 'datetime',
            'annullata_at' => 'datetime',
            'checkin_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Evento, $this>
     */
    public function evento(): BelongsTo
    {
        return $this->belongsTo(Evento::class, 'evento_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    /**
     * Operatore che ha effettuato il check-in.
     *
     * @return BelongsTo<Utente, $this>
     */
    public function checkinUtente(): BelongsTo
    {
        return $this->belongsTo(Utente::class, 'checkin_utente_id');
    }

    /**
     * Prenotazioni che consumano posti (confermate o in attesa di pagamento).
     *
     * @param  Builder<Prenotazione>  $query
     * @return Builder<Prenotazione>
     */
    public function scopeAttive(Builder $query): Builder
    {
        return $query->whereIn('stato', self::STATI_ATTIVI);
    }

    /**
     * @param  Builder<Prenotazione>  $query
     * @return Builder<Prenotazione>
     */
    public function scopeConfermate(Builder $query): Builder
    {
        return $query->where('stato', self::STATO_CONFERMATA);
    }

    /**
     * Lista d'attesa in ordine FIFO stretto.
     *
     * @param  Builder<Prenotazione>  $query
     * @return Builder<Prenotazione>
     */
    public function scopeInListaAttesa(Builder $query): Builder
    {
        return $query->where('stato', self::STATO_LISTA_ATTESA)->orderBy('created_at');
    }

    /**
     * In attesa di pagamento con finestra scaduta (candidate a 'scaduta').
     *
     * @param  Builder<Prenotazione>  $query
     * @return Builder<Prenotazione>
     */
    public function scopePagamentoScaduto(Builder $query): Builder
    {
        return $query->where('stato', self::STATO_IN_ATTESA_PAGAMENTO)
            ->where('scadenza_pagamento_at', '<', now());
    }

    public function isAttiva(): bool
    {
        return in_array($this->stato, self::STATI_ATTIVI, true);
    }

    /**
     * Posti occupati di un evento: SUM(posti) sulle prenotazioni attive.
     * Sola lettura: per le scritture il conteggio va rifatto DENTRO la
     * transazione con lockForUpdate() sulla riga eventi.
     */
    public static function postiOccupati(string $eventoId): int
    {
        return (int) static::query()
            ->withoutGlobalScope('associazione')
            ->where('evento_id', $eventoId)
            ->whereIn('stato', self::STATI_ATTIVI)
            ->sum('posti');
    }

    /**
     * Genera un codice prenotazione leggibile, es. PRN-7K3M2A.
     */
    public static function generaCodice(): string
    {
        $alfabeto = self::ALFABETO_CODICE;
        $codice = '';

        for ($i = 0; $i < 6; $i++) {
            $codice .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }

        return 'PRN-'.$codice;
    }

    /**
     * Genera il token QR del biglietto (64 hex, unique globale).
     */
    public static function generaQrToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    // ------------------------------------------------------------------
    // Pagabile (checkout Stripe/PayPal)
    // ------------------------------------------------------------------

    public function getPagabileId(): string
    {
        return (string) $this->id;
    }

    public function getPagabileTipo(): string
    {
        return Pagabile::TIPO_PRENOTAZIONE;
    }

    public function getAssociazioneId(): string
    {
        return (string) $this->associazione_id;
    }

    public function getImporto(): float
    {
        return (float) $this->importo_totale;
    }

    public function getDescrizioneRiga(): string
    {
        $posti = (int) $this->posti;

        return ($this->associazione->nome ?? 'Associazione')
            .' - '.($this->evento->titolo ?? 'Evento')
            ." × {$posti} ".($posti === 1 ? 'posto' : 'posti')
            ." ({$this->codice})";
    }

    public function getEmailPagatore(): ?string
    {
        $email = $this->account()->withTrashed()->first()?->email;

        return filled($email) ? $email : null;
    }
}
