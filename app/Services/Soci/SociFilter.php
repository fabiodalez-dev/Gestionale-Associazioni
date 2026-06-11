<?php

namespace App\Services\Soci;

use App\Models\Associazione;
use App\Models\GruppoDinamico;
use App\Models\Socio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Filtri della lista soci (spec soci §1.9):
 * - ricerca full-text via Scout (miglioramento 14) su nome/cognome/email/CF/numero socio;
 * - default tessera_stato=attive (saltato per gli stati pending);
 * - gruppo dinamico esclusivo (ignora tutti gli altri filtri, whitelist colonne);
 * - filtri sull'ultima tessera (relazione ultimaTessera).
 */
class SociFilter
{
    /** @var list<string> colonne ammesse nei filtri dei gruppi dinamici */
    public const GRUPPO_WHITELIST = [
        'nome', 'cognome', 'email', 'stato',
        'tipo_socio_id', 'categoria_socio_id', 'sede_id',
        'citta', 'provincia',
    ];

    public const DEFAULTS = [
        'search' => '',
        'status' => 'all',
        'sede_id' => 'all',
        'categoria_ids' => [],
        'has_tessera' => 'all',
        'tessera_stato' => 'attive',
        'tessera_template' => 'all',
        'tessera_scadenza' => 'all',
        'gruppo_id' => 'all',
    ];

    /** @var array<string, mixed> */
    private array $params;

    public function __construct(array $params, private readonly ?Associazione $associazione = null)
    {
        $this->params = array_merge(self::DEFAULTS, array_filter(
            $params,
            fn ($v) => $v !== null && $v !== ''
        ));

        if (! is_array($this->params['categoria_ids'])) {
            $this->params['categoria_ids'] = [$this->params['categoria_ids']];
        }
    }

    /** @return array<string, mixed> */
    public function params(): array
    {
        return $this->params;
    }

    public function isGruppoAttivo(): bool
    {
        return $this->params['gruppo_id'] !== 'all' && filled($this->params['gruppo_id']);
    }

    /** @return Builder<Socio> */
    public function build(): Builder
    {
        $query = Socio::query()
            ->with(['tipoSocio', 'categoriaSocio', 'sede', 'tags', 'ultimaTessera', 'associazione'])
            ->orderBy('cognome')
            ->orderBy('nome');

        // Gruppo dinamico esclusivo: applica SOLO i filtri del gruppo (spec §1.9)
        if ($this->isGruppoAttivo()) {
            $gruppo = GruppoDinamico::query()->find($this->params['gruppo_id']);

            if ($gruppo !== null) {
                foreach ((array) $gruppo->filtri_json as $colonna => $valore) {
                    if (in_array($colonna, self::GRUPPO_WHITELIST, true) && filled($valore)) {
                        $query->where($colonna, $valore);
                    }
                }
            }

            return $query;
        }

        $search = trim((string) $this->params['search']);

        if ($search !== '') {
            // Ricerca via Scout (driver database): le chiavi vengono poi
            // ri-filtrate dalla query tenant-scoped (difesa in profondità).
            $ids = Socio::search($search)->keys();
            $query->whereIn('soci.id', $ids->all());
        }

        $status = (string) $this->params['status'];

        if ($status !== 'all' && in_array($status, Socio::STATI, true)) {
            $query->where('stato', $status);
        }

        if ($this->params['sede_id'] !== 'all' && filled($this->params['sede_id'])) {
            $query->where('sede_id', $this->params['sede_id']);
        }

        $categoriaIds = array_values(array_filter((array) $this->params['categoria_ids']));

        if ($categoriaIds !== []) {
            $query->whereIn('categoria_socio_id', $categoriaIds);
        }

        if ($this->params['has_tessera'] === 'yes') {
            $query->whereHas('ultimaTessera');
        } elseif ($this->params['has_tessera'] === 'no') {
            $query->whereDoesntHave('ultimaTessera');
        }

        $oggi = Carbon::today()->toDateString();
        $statiPending = [Socio::STATO_IN_ATTESA, Socio::STATO_IN_ATTESA_DI_PAGAMENTO];

        // Filtro stato tessera (default 'attive'), saltato per gli stati pending (spec §1.9)
        if (! in_array($status, $statiPending, true)) {
            if ($this->params['tessera_stato'] === 'attive') {
                $query->whereHas('ultimaTessera', function (Builder $t) use ($oggi) {
                    $t->where('stato', 'Attiva')
                        ->where(function (Builder $w) use ($oggi) {
                            $w->whereNull('data_scadenza')->orWhereDate('data_scadenza', '>=', $oggi);
                        });
                });
            } elseif ($this->params['tessera_stato'] === 'scadute') {
                $query->whereHas('ultimaTessera', function (Builder $t) use ($oggi) {
                    $t->where(function (Builder $w) use ($oggi) {
                        $w->where('stato', 'Scaduta')->orWhereDate('data_scadenza', '<', $oggi);
                    });
                });
            }
        }

        if ($this->params['tessera_template'] !== 'all' && filled($this->params['tessera_template'])) {
            $query->whereHas('ultimaTessera', fn (Builder $t) => $t->where('template_tessera', $this->params['tessera_template']));
        }

        $giorniNotifica = (int) ($this->associazione->giorni_notifica_scadenza ?? 30);

        if ($this->params['tessera_scadenza'] === 'in_scadenza') {
            $limite = Carbon::today()->addDays($giorniNotifica)->toDateString();
            $query->whereHas('ultimaTessera', function (Builder $t) use ($oggi, $limite) {
                $t->whereNotNull('data_scadenza')->whereBetween('data_scadenza', [$oggi, $limite]);
            });
        } elseif ($this->params['tessera_scadenza'] === 'scadute') {
            $query->whereHas('ultimaTessera', function (Builder $t) use ($oggi) {
                $t->whereNotNull('data_scadenza')->whereDate('data_scadenza', '<', $oggi);
            });
        }

        return $query;
    }
}
