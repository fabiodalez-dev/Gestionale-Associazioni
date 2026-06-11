<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SmtpSetting extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasUuids;

    public const ENCRYPTIONS = ['tls', 'ssl', 'none'];

    protected $table = 'smtp_settings';

    protected $fillable = [
        'associazione_id',
        'smtp_host',
        'smtp_port',
        'smtp_user',
        'smtp_pass_encrypted',
        'smtp_encryption',
        'from_email',
        'from_name',
        'reply_to_email',
        'reply_to_name',
        'is_verified',
        'max_per_hour',
        'auto_benvenuto',
        'auto_scadenza_tessera',
        'auto_scadenza_quota',
        'auto_rinnovo_tessera',
        'auto_pagamento_quota',
        'last_test_at',
    ];

    protected $hidden = [
        'smtp_pass_encrypted',
    ];

    /** @var list<string> attributi esclusi dall'activity log */
    protected array $activityLogExcept = [
        'smtp_pass_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'smtp_port' => 'integer',
            // Cifratura applicativa gestita da Laravel (sostituisce l'AES-256-CBC custom del legacy)
            'smtp_pass_encrypted' => 'encrypted',
            'is_verified' => 'boolean',
            'max_per_hour' => 'integer',
            'auto_benvenuto' => 'boolean',
            'auto_scadenza_tessera' => 'boolean',
            'auto_scadenza_quota' => 'boolean',
            'auto_rinnovo_tessera' => 'boolean',
            'auto_pagamento_quota' => 'boolean',
            'last_test_at' => 'datetime',
        ];
    }
}
