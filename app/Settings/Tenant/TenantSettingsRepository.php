<?php

namespace App\Settings\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Spatie\LaravelSettings\SettingsRepositories\DatabaseSettingsRepository;

/**
 * Repository di spatie/laravel-settings con una riga per associazione:
 * ogni query è filtrata per l'associazione di ContestoImpostazioni e
 * fallisce se il contesto manca.
 */
class TenantSettingsRepository extends DatabaseSettingsRepository
{
    public function getBuilder(): Builder
    {
        return parent::getBuilder()->where('associazione_id', ContestoImpostazioni::richiesto());
    }

    public function createProperty(string $group, string $name, $payload, bool $locked = false): void
    {
        parent::getBuilder()->create([
            'associazione_id' => ContestoImpostazioni::richiesto(),
            'group' => $group,
            'name' => $name,
            'payload' => $this->encode($payload),
            'locked' => $locked,
        ]);
    }

    public function updatePropertiesPayload(string $group, array $properties): void
    {
        $associazioneId = ContestoImpostazioni::richiesto();
        $adesso = now();

        $righe = collect($properties)->map(fn ($payload, $name) => [
            'associazione_id' => $associazioneId,
            'group' => $group,
            'name' => $name,
            'payload' => $this->encode($payload),
            'locked' => false,
            'created_at' => $adesso,
            'updated_at' => $adesso,
        ])->values()->all();

        if ($righe === []) {
            return;
        }

        parent::getBuilder()->upsert($righe, ['associazione_id', 'group', 'name'], ['payload', 'updated_at']);
    }
}
