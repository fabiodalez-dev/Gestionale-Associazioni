<?php

namespace App\Services\Email;

use App\Models\GruppoDinamico;
use App\Models\Socio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Selezione destinatari delle comunicazioni massive.
 *
 * Regola di eleggibilità comune (come legacy):
 * - email presente, stato 'Attivo', non opt-out.
 *
 * Tipi di filtro: all | category | section | tag | gruppo.
 * Il filtro 'gruppo' (incompleto nel legacy: inviava a tutti) applica davvero
 * i filtri_json del gruppo dinamico (chiavi note: stato, citta).
 */
class RecipientSelector
{
    public const TYPES = ['all', 'category', 'section', 'tag', 'gruppo'];

    /**
     * @return Collection<int, Socio>
     */
    public function recipients(string $associazioneId, string $type, ?string $filterId = null): Collection
    {
        return $this->query($associazioneId, $type, $filterId)
            ->get(['id', 'associazione_id', 'email', 'nome', 'cognome', 'numero_socio', 'data_iscrizione', 'email_opt_out_token']);
    }

    public function count(string $associazioneId, string $type, ?string $filterId = null): int
    {
        return $this->query($associazioneId, $type, $filterId)->count();
    }

    /**
     * @return Builder<Socio>
     */
    public function query(string $associazioneId, string $type, ?string $filterId = null): Builder
    {
        $query = Socio::forAssociazione($associazioneId)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->where('stato', 'Attivo')
            ->where(function (Builder $q) {
                $q->where('email_opt_out', false)->orWhereNull('email_opt_out');
            });

        switch ($type) {
            case 'category':
                $query->where('categoria_socio_id', $filterId);
                break;

            case 'section':
                $query->where('sede_id', $filterId);
                break;

            case 'tag':
                $query->whereExists(function ($q) use ($filterId) {
                    $q->selectRaw('1')
                        ->from('socio_tags')
                        ->whereColumn('socio_tags.socio_id', 'soci.id')
                        ->where('socio_tags.tag_id', $filterId);
                });
                break;

            case 'gruppo':
                $this->applyGruppoDinamico($query, $associazioneId, $filterId);
                break;

            case 'all':
            default:
                break;
        }

        return $query;
    }

    /**
     * Applica i filtri_json del gruppo dinamico (legacy: {stato?, citta?}).
     * Nota: la condizione stato='Attivo' di eleggibilità resta sempre valida
     * (un gruppo con stato diverso da 'Attivo' produce zero destinatari).
     *
     * @param  Builder<Socio>  $query
     */
    private function applyGruppoDinamico(Builder $query, string $associazioneId, ?string $gruppoId): void
    {
        $gruppo = GruppoDinamico::forAssociazione($associazioneId)
            ->whereKey($gruppoId)
            ->first();

        if ($gruppo === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $raw = $gruppo->getAttribute('filtri_json');

        $filtri = [];
        if (is_array($raw)) {
            $filtri = $raw;
        } elseif (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $filtri = is_array($decoded) ? $decoded : [];
        }

        if (! empty($filtri['stato']) && is_string($filtri['stato'])) {
            $query->where('stato', $filtri['stato']);
        }

        if (! empty($filtri['citta']) && is_string($filtri['citta'])) {
            $query->where('citta', $filtri['citta']);
        }
    }
}
