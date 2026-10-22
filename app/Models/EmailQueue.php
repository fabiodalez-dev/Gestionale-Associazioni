<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Coda email applicativa (alto volume: non usa LogsActivity).
 *
 * @property string $id
 * @property string $associazione_id
 * @property string $batch_id
 * @property string|null $socio_id
 * @property string $to_email
 * @property string|null $to_name
 * @property string $subject
 * @property string $body_html
 * @property string|null $template_codice
 * @property int $priority
 * @property string $status
 * @property int $attempts
 * @property int $max_attempts
 * @property Carbon|null $last_attempt_at
 * @property Carbon|null $next_attempt_at
 * @property string|null $error_message
 * @property Carbon|null $sent_at
 * @property Carbon|null $created_at
 * @property-read Socio|null $socio
 */
class EmailQueue extends Model
{
    use BelongsToAssociazione;
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'email_queue';

    public const UPDATED_AT = null;

    protected $fillable = [
        'associazione_id',
        'batch_id',
        'socio_id',
        'to_email',
        'to_name',
        'subject',
        'body_html',
        'template_codice',
        'priority',
        'status',
        'attempts',
        'max_attempts',
        'last_attempt_at',
        'next_attempt_at',
        'error_message',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'last_attempt_at' => 'datetime',
            'next_attempt_at' => 'datetime',
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
}
