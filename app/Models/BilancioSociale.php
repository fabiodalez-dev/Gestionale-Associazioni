<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bilancio sociale di un anno (issue #15): bozza → approvato (con il verbale
 * dell'assemblea) → pubblicato (PDF in Trasparenza). I dati sono uno
 * snapshot del gestionale, i testi si scrivono per sezione.
 */
class BilancioSociale extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasUuids;

    public const STATO_BOZZA = 'bozza';

    public const STATO_APPROVATO = 'approvato';

    public const STATO_PUBBLICATO = 'pubblicato';

    public const STATI = [
        self::STATO_BOZZA => 'Bozza',
        self::STATO_APPROVATO => 'Approvato',
        self::STATO_PUBBLICATO => 'Pubblicato',
    ];

    /**
     * Sezioni delle linee guida (DM 4 luglio 2019) con le domande guida per
     * i testi.
     */
    public const SEZIONI = [
        'metodologia' => [
            'titolo' => 'Metodologia adottata per la redazione del bilancio sociale',
            'guida' => 'Chi ha redatto il bilancio sociale, con quali fonti, chi è stato coinvolto, cosa è cambiato rispetto all\'anno precedente.',
        ],
        'ente' => [
            'titolo' => 'Informazioni generali sull\'ente',
            'guida' => 'Missione e valori, attività statutarie, territorio in cui operate, reti e collaborazioni.',
        ],
        'governo' => [
            'titolo' => 'Struttura, governo e amministrazione',
            'guida' => 'Come funzionano gli organi, come si decide, come partecipano i soci.',
        ],
        'persone' => [
            'titolo' => 'Persone che operano per l\'ente',
            'guida' => 'Soci, volontari e collaboratori: chi sono, come sono coinvolti, formazione e riconoscimenti.',
        ],
        'attivita' => [
            'titolo' => 'Obiettivi e attività',
            'guida' => 'Gli obiettivi dell\'anno, le attività svolte, i risultati e le persone raggiunte; cosa non è andato come previsto.',
        ],
        'economia' => [
            'titolo' => 'Situazione economico-finanziaria',
            'guida' => 'Da dove vengono le risorse e come sono state usate, raccolte fondi, eventuali criticità.',
        ],
        'altre' => [
            'titolo' => 'Altre informazioni',
            'guida' => 'Contenziosi, impatto ambientale, parità di genere, altre informazioni utili a chi legge.',
        ],
        'controllo' => [
            'titolo' => 'Monitoraggio svolto dall\'organo di controllo',
            'guida' => 'Relazione dell\'organo di controllo, se presente.',
        ],
    ];

    protected $table = 'bilanci_sociali';

    protected $attributes = [
        'stato' => self::STATO_BOZZA,
    ];

    protected $fillable = [
        'associazione_id',
        'anno',
        'stato',
        'dati',
        'testi',
        'verbale_id',
        'documento_id',
        'generato_at',
        'approvato_at',
        'pubblicato_at',
    ];

    protected function casts(): array
    {
        return [
            'anno' => 'integer',
            'dati' => 'array',
            'testi' => 'array',
            'generato_at' => 'datetime',
            'approvato_at' => 'datetime',
            'pubblicato_at' => 'datetime',
        ];
    }

    public function statoLabel(): string
    {
        return self::STATI[$this->stato] ?? $this->stato;
    }

    public function testo(string $sezione): string
    {
        return (string) (($this->testi ?? [])[$sezione] ?? '');
    }

    /**
     * @return BelongsTo<Verbale, $this>
     */
    public function verbale(): BelongsTo
    {
        return $this->belongsTo(Verbale::class, 'verbale_id');
    }

    /**
     * @return BelongsTo<Documento, $this>
     */
    public function documento(): BelongsTo
    {
        return $this->belongsTo(Documento::class, 'documento_id');
    }
}
