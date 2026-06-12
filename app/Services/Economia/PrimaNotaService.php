<?php

namespace App\Services\Economia;

use App\Models\CategoriaMovimento;
use App\Models\Donazione;
use App\Models\Movimento;
use App\Models\Prenotazione;
use App\Models\Quota;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Prima nota (f.13 del piano "Gestione 2"): categorie di movimento
 * seedate per tenant al primo accesso e movimenti automatici generati
 * dai pagamenti (quota pagata, prenotazione incassata, donazione).
 *
 * I metodi *BestEffort sono pensati per essere agganciati ai flussi di
 * pagamento esistenti (PaymentService::onPaymentCompleted,
 * PrenotazioneService::onPagamentoCompletato) SENZA romperli: non
 * sollevano mai eccezioni e sono idempotenti — l'unique a DB su
 * (origine_type, origine_id) garantisce al massimo un movimento per
 * origine anche su webhook replay o race.
 */
class PrimaNotaService
{
    /** Categorie seedate per tenant al primo accesso (f.13). */
    public const CATEGORIE_DEFAULT = [
        ['nome' => 'Quote associative', 'tipo' => CategoriaMovimento::TIPO_ENTRATA],
        ['nome' => 'Eventi', 'tipo' => CategoriaMovimento::TIPO_ENTRATA],
        ['nome' => 'Donazioni', 'tipo' => CategoriaMovimento::TIPO_ENTRATA],
        ['nome' => 'Altre entrate', 'tipo' => CategoriaMovimento::TIPO_ENTRATA],
        ['nome' => 'Spese eventi', 'tipo' => CategoriaMovimento::TIPO_USCITA],
        ['nome' => 'Utenze e affitti', 'tipo' => CategoriaMovimento::TIPO_USCITA],
        ['nome' => 'Materiali e forniture', 'tipo' => CategoriaMovimento::TIPO_USCITA],
        ['nome' => 'Altre uscite', 'tipo' => CategoriaMovimento::TIPO_USCITA],
    ];

    public const CATEGORIA_QUOTE = 'Quote associative';

    public const CATEGORIA_EVENTI = 'Eventi';

    public const CATEGORIA_DONAZIONI = 'Donazioni';

    /**
     * Seeding idempotente delle categorie del tenant: al primo accesso
     * (nessuna categoria presente) crea il set di default. Race-safe:
     * l'unique (associazione, nome, tipo) + catch assorbe i duplicati.
     */
    public function seedCategorie(string $associazioneId): void
    {
        $esistenti = CategoriaMovimento::allTenants()
            ->where('associazione_id', $associazioneId)
            ->exists();

        if ($esistenti) {
            return;
        }

        foreach (self::CATEGORIE_DEFAULT as $categoria) {
            try {
                CategoriaMovimento::allTenants()->firstOrCreate([
                    'associazione_id' => $associazioneId,
                    'nome' => $categoria['nome'],
                    'tipo' => $categoria['tipo'],
                ]);
            } catch (QueryException) {
                // Già creata in concorrenza: ok
            }
        }
    }

    /**
     * Categoria del tenant per nome/tipo, creandola se assente
     * (i movimenti automatici non devono mai fallire per categoria mancante).
     */
    public function categoria(string $associazioneId, string $nome, string $tipo): ?CategoriaMovimento
    {
        try {
            $this->seedCategorie($associazioneId);

            return CategoriaMovimento::allTenants()->firstOrCreate([
                'associazione_id' => $associazioneId,
                'nome' => $nome,
                'tipo' => $tipo,
            ]);
        } catch (QueryException) {
            return CategoriaMovimento::allTenants()
                ->where('associazione_id', $associazioneId)
                ->where('nome', $nome)
                ->where('tipo', $tipo)
                ->first();
        }
    }

    // ------------------------------------------------------------------
    // Hook automatici (idempotenti, best-effort)
    // ------------------------------------------------------------------

    /**
     * Quota pagata → movimento di entrata in prima nota (f.13).
     * Best-effort e idempotente: mai eccezioni, al massimo un movimento
     * per quota (unique su origine). No-op se la quota non risulta pagata.
     */
    public function registraIncassoQuotaBestEffort(string $quotaId): ?Movimento
    {
        try {
            /** @var Quota|null $quota */
            $quota = Quota::allTenants()->find($quotaId);

            if ($quota === null || $quota->data_pagamento === null) {
                return null;
            }

            $socio = $quota->socio()->withTrashed()->first();
            $nomeSocio = $socio !== null ? trim($socio->cognome.' '.$socio->nome) : null;

            return $this->creaMovimentoPerOrigine($quota, [
                'associazione_id' => $quota->associazione_id,
                'tipo' => Movimento::TIPO_ENTRATA,
                'importo' => (float) $quota->importo,
                'data' => $quota->data_pagamento->toDateString(),
                'categoria_id' => $this->categoria($quota->associazione_id, self::CATEGORIA_QUOTE, CategoriaMovimento::TIPO_ENTRATA)?->id,
                'descrizione' => 'Quota '.$quota->anno.($nomeSocio !== null && $nomeSocio !== '' ? ' — '.$nomeSocio : ''),
                'metodo' => $quota->metodo_pagamento ?: $quota->gateway,
            ]);
        } catch (\Throwable $e) {
            Log::warning('PrimaNotaService::registraIncassoQuotaBestEffort', [
                'quota_id' => $quotaId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Prenotazione incassata → movimento di entrata in prima nota (f.13).
     * Best-effort e idempotente come per le quote. No-op se non pagata
     * o a importo zero (eventi gratuiti).
     */
    public function registraIncassoPrenotazioneBestEffort(Prenotazione $prenotazione): ?Movimento
    {
        try {
            if ($prenotazione->pagata_at === null || (float) $prenotazione->importo_totale <= 0) {
                return null;
            }

            $evento = $prenotazione->evento()->withoutGlobalScope('associazione')->first();

            return $this->creaMovimentoPerOrigine($prenotazione, [
                'associazione_id' => $prenotazione->associazione_id,
                'tipo' => Movimento::TIPO_ENTRATA,
                'importo' => (float) $prenotazione->importo_totale,
                'data' => $prenotazione->pagata_at->toDateString(),
                'categoria_id' => $this->categoria($prenotazione->associazione_id, self::CATEGORIA_EVENTI, CategoriaMovimento::TIPO_ENTRATA)?->id,
                'descrizione' => 'Prenotazione '.$prenotazione->codice.($evento !== null ? ' — '.$evento->titolo : ''),
                'metodo' => $prenotazione->gateway,
            ]);
        } catch (\Throwable $e) {
            Log::warning('PrimaNotaService::registraIncassoPrenotazioneBestEffort', [
                'prenotazione_id' => $prenotazione->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Donazione pagata → movimento di entrata in prima nota (f.15).
     */
    public function registraDonazioneBestEffort(Donazione $donazione): ?Movimento
    {
        try {
            if (! $donazione->isPagata()) {
                return null;
            }

            return $this->creaMovimentoPerOrigine($donazione, [
                'associazione_id' => $donazione->associazione_id,
                'tipo' => Movimento::TIPO_ENTRATA,
                'importo' => (float) $donazione->importo,
                'data' => ($donazione->pagata_at ?? now())->toDateString(),
                'categoria_id' => $this->categoria($donazione->associazione_id, self::CATEGORIA_DONAZIONI, CategoriaMovimento::TIPO_ENTRATA)?->id,
                'descrizione' => 'Donazione — '.$donazione->nomePubblico(),
                'metodo' => $donazione->gateway,
            ]);
        } catch (\Throwable $e) {
            Log::warning('PrimaNotaService::registraDonazioneBestEffort', [
                'donazione_id' => $donazione->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Crea il movimento collegato all'origine se non esiste già.
     * Idempotenza a doppio livello: exists-check + unique DB
     * (origine_type, origine_id) con catch della violazione.
     *
     * @param  array{associazione_id: string, tipo: string, importo: float, data: string, categoria_id: string|null, descrizione: string, metodo: string|null}  $attributi
     */
    protected function creaMovimentoPerOrigine(Model $origine, array $attributi): ?Movimento
    {
        $esiste = Movimento::allTenants()
            ->where('origine_type', $origine->getMorphClass())
            ->where('origine_id', $origine->getKey())
            ->exists();

        if ($esiste) {
            return null;
        }

        try {
            return Movimento::create([
                ...$attributi,
                'origine_type' => $origine->getMorphClass(),
                'origine_id' => $origine->getKey(),
            ]);
        } catch (QueryException) {
            // Race sul webhook replay: il movimento esiste già
            return null;
        }
    }
}
