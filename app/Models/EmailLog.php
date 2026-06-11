<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Log email append-only di soli esiti definitivi (non usa LogsActivity).
 *
 * @property string $id
 * @property string $associazione_id
 * @property string|null $batch_id
 * @property string|null $queue_id
 * @property string|null $socio_id
 * @property string $to_email
 * @property string|null $to_name
 * @property string $subject
 * @property string|null $template_codice
 * @property string|null $body_html
 * @property string $status
 * @property string|null $error_message
 * @property string|null $sent_by
 * @property Carbon|null $sent_at
 * @property-read Socio|null $socio
 * @property-read Utente|null $sentBy
 */
class EmailLog extends Model
{
    use BelongsToAssociazione;
    use HasUuids;

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $table = 'email_log';

    public $timestamps = false;

    protected $fillable = [
        'associazione_id',
        'batch_id',
        'queue_id',
        'socio_id',
        'to_email',
        'to_name',
        'subject',
        'template_codice',
        'body_html',
        'status',
        'error_message',
        'sent_by',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }

    /**
     * @return BelongsTo<Utente, $this>
     */
    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(Utente::class, 'sent_by');
    }
}
