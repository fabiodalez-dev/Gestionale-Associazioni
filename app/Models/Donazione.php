<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use App\Services\Pagamenti\Pagabile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Donazione online (f.15 del piano "Gestione 2"): importo libero dalla pagina
 * pubblica /a/{slug}/dona, pagamento Stripe/PayPal via interfaccia Pagabile,
 * opzionalmente anonima. La donazione pagata genera il movimento automatico
 * in prima nota (hook idempotente del modulo).
 */
class Donazione extends Model implements Pagabile
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;

    public const STATO_IN_ATTESA = 'in_attesa';

    public const STATO_PAGATA = 'pagata';

    public const STATO_ANNULLATA = 'annullata';

    public const STATI = [
        self::STATO_IN_ATTESA,
        self::STATO_PAGATA,
        self::STATO_ANNULLATA,
    ];

    protected $table = 'donazioni';

    /** @var array<string, mixed> */
    protected $attributes = [
        'anonimo' => false,
        'stato' => self::STATO_IN_ATTESA,
    ];

    protected $fillable = [
        'associazione_id',
        'donatore_nome',
        'donatore_email',
        'importo',
        'anonimo',
        'stato',
        'gateway',
        'transaction_id',
        'pagata_at',
    ];

    protected function casts(): array
    {
        return [
            'importo' => 'decimal:2',
            'anonimo' => 'boolean',
            'pagata_at' => 'datetime',
        ];
    }

    public function isPagata(): bool
    {
        return $this->stato === self::STATO_PAGATA;
    }

    /**
     * @param  Builder<Donazione>  $query
     * @return Builder<Donazione>
     */
    public function scopePagate(Builder $query): Builder
    {
        return $query->where('stato', self::STATO_PAGATA);
    }

    /**
     * Nome mostrabile negli elenchi: rispetta l'anonimato.
     */
    public function nomePubblico(): string
    {
        if ($this->anonimo || blank($this->donatore_nome)) {
            return 'Anonimo';
        }

        return (string) $this->donatore_nome;
    }

    /**
     * Movimento di prima nota generato dalla donazione pagata (f.13/f.15).
     *
     * @return MorphOne<Movimento, $this>
     */
    public function movimento(): MorphOne
    {
        return $this->morphOne(Movimento::class, 'origine');
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
        return Pagabile::TIPO_DONAZIONE;
    }

    public function getAssociazioneId(): string
    {
        return (string) $this->associazione_id;
    }

    public function getImporto(): float
    {
        return (float) $this->importo;
    }

    public function getDescrizioneRiga(): string
    {
        return 'Donazione liberale';
    }

    public function getEmailPagatore(): ?string
    {
        return $this->donatore_email;
    }
}
