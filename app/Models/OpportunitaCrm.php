<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Opportunità del CRM a colonne (nuova adesione, sponsor, volontario).
 *
 * @property string $id
 * @property string $associazione_id
 * @property string $pipeline
 * @property string $fase
 * @property int $posizione
 * @property string $titolo
 * @property string|null $descrizione
 * @property string|null $origine
 * @property string|null $valore
 * @property Carbon|null $prossima_azione_il
 * @property string|null $email
 * @property string|null $telefono
 * @property string|null $responsabile_id
 * @property string|null $socio_id
 * @property string|null $esito
 * @property Carbon|null $chiusa_at
 */
class OpportunitaCrm extends Model
{
    use BelongsToAssociazione;
    use HasUuids;
    use SoftDeletes;

    /**
     * Pipeline e loro fasi, in ordine. L'ultima fase di ogni pipeline è
     * quella "vinta" (adesione completata, sponsor firmato, volontario attivo).
     */
    public const PIPELINE = [
        'adesioni' => [
            'nome' => 'Adesioni',
            'titolo' => 'Nuove adesioni',
            'fasi' => ['contatto' => 'Contatto', 'qualificato' => 'Qualificato', 'proposta' => 'Proposta inviata', 'pagamento' => 'In attesa di pagamento', 'iscritto' => 'Iscritto'],
        ],
        'sponsor' => [
            'nome' => 'Sponsor',
            'titolo' => 'Sponsor e partner',
            'fasi' => ['contatto' => 'Contatto', 'incontro' => 'Incontro', 'proposta' => 'Proposta inviata', 'trattativa' => 'Trattativa', 'firmato' => 'Firmato'],
        ],
        'volontari' => [
            'nome' => 'Volontari',
            'titolo' => 'Nuovi volontari',
            'fasi' => ['candidatura' => 'Candidatura', 'colloquio' => 'Colloquio', 'formazione' => 'Formazione', 'attivo' => 'Attivo'],
        ],
    ];

    public const ORIGINI = [
        'modulo_web' => 'Modulo web',
        'passaparola' => 'Passaparola',
        'open_day' => 'Open day',
        'evento' => 'Evento',
        'social' => 'Social',
        'contatto_diretto' => 'Contatto diretto',
        'altro' => 'Altro',
    ];

    protected $table = 'crm_opportunita';

    protected $fillable = [
        'associazione_id', 'pipeline', 'fase', 'posizione', 'titolo', 'descrizione', 'origine', 'valore',
        'prossima_azione_il', 'email', 'telefono', 'responsabile_id', 'socio_id', 'contatto_esterno_id', 'esito', 'chiusa_at',
    ];

    protected function casts(): array
    {
        return [
            'posizione' => 'integer',
            'valore' => 'decimal:2',
            'prossima_azione_il' => 'date',
            'chiusa_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Utente, $this>
     */
    public function responsabile(): BelongsTo
    {
        return $this->belongsTo(Utente::class, 'responsabile_id');
    }

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }

    /**
     * @param  Builder<OpportunitaCrm>  $query
     * @return Builder<OpportunitaCrm>
     */
    public function scopeAperte(Builder $query): Builder
    {
        return $query->whereNull('esito');
    }

    /**
     * @return array<string, string>
     */
    public static function fasi(string $pipeline): array
    {
        return self::PIPELINE[$pipeline]['fasi'] ?? [];
    }

    public static function faseFinale(string $pipeline): ?string
    {
        $fasi = array_keys(self::fasi($pipeline));

        return $fasi === [] ? null : end($fasi);
    }

    public function inRitardo(): bool
    {
        return $this->esito === null && $this->prossima_azione_il !== null && $this->prossima_azione_il->lt(now()->startOfDay());
    }
}
