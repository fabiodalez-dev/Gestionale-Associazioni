<?php

namespace App\Services\Soci;

use App\Models\CampoPersonalizzato;
use App\Models\Socio;
use App\Models\ValoreCampoPersonalizzato;
use Illuminate\Support\Facades\DB;

/**
 * Creazione/aggiornamento socio con side effect (spec soci §1.3/§1.4):
 * progressivo numero socio, storico attività, tessera automatica anno corrente,
 * campi personalizzati (upsert, checkbox deselezionata azzerata — fix bug legacy)
 * e sync dei tag (validati tenant a monte dalla Form Request).
 */
class SocioService
{
    public function __construct(
        private readonly NumeroProgressivoService $progressivi,
        private readonly TesseraAutomaticaService $tessere,
        private readonly SocioActivityLogger $activityLogger,
        private readonly EmailBenvenutoService $emailBenvenuto,
    ) {}

    /**
     * @param  array<string, mixed>  $dati  attributi anagrafici validati
     * @param  list<string>  $tagIds
     * @param  array<string, mixed>  $campiPersonalizzati  campo_id => valore
     */
    public function crea(array $dati, array $tagIds = [], array $campiPersonalizzati = [], bool $generaTessera = true): Socio
    {
        $socio = DB::transaction(function () use ($dati, $tagIds, $campiPersonalizzati, $generaTessera): Socio {
            $associazioneId = $dati['associazione_id'] ?? Socio::currentAssociazioneId();

            if (blank($dati['numero_socio'] ?? null)) {
                $dati['numero_socio'] = $this->progressivi->prossimoNumeroSocio($associazioneId);
            }

            $socio = Socio::create($dati);

            $this->activityLogger->log(
                $socio,
                SocioActivityLogger::TIPO_ISCRIZIONE,
                "Nuovo socio iscritto con n. {$socio->numero_socio}"
            );

            if ($generaTessera) {
                $this->tessere->creaPerAnnoCorrente($socio);
            }

            $this->salvaCampiPersonalizzati($socio, $campiPersonalizzati);
            $socio->tags()->sync($tagIds);

            return $socio;
        });

        // Fuori transazione, best-effort (spec §1.3 punto 11)
        $this->emailBenvenuto->accoda($socio);

        return $socio;
    }

    /**
     * @param  array<string, mixed>  $dati
     * @param  list<string>  $tagIds
     * @param  array<string, mixed>  $campiPersonalizzati
     */
    public function aggiorna(Socio $socio, array $dati, array $tagIds = [], array $campiPersonalizzati = []): Socio
    {
        return DB::transaction(function () use ($socio, $dati, $tagIds, $campiPersonalizzati): Socio {
            $socio->update($dati);

            $this->activityLogger->log(
                $socio,
                SocioActivityLogger::TIPO_MODIFICA_ANAGRAFICA,
                "L'anagrafica del socio è stata aggiornata."
            );

            $this->salvaCampiPersonalizzati($socio, $campiPersonalizzati);
            $socio->tags()->sync($tagIds);

            return $socio;
        });
    }

    /**
     * Upsert dei valori dei campi personalizzati. A differenza del legacy,
     * una checkbox deselezionata (assente dal POST) azzera il valore salvato.
     *
     * @param  array<string, mixed>  $valori  campo_id => valore
     */
    public function salvaCampiPersonalizzati(Socio $socio, array $valori): void
    {
        $campi = CampoPersonalizzato::allTenants()
            ->where('associazione_id', $socio->associazione_id)
            ->get();

        foreach ($campi as $campo) {
            $presente = array_key_exists($campo->id, $valori);

            if ($campo->tipo_campo === 'checkbox') {
                $valore = $presente && filled($valori[$campo->id]) ? '1' : '0';
            } elseif ($presente) {
                $valore = $valori[$campo->id] === null ? null : trim((string) $valori[$campo->id]);
            } else {
                continue; // campo non inviato (non checkbox): nessun upsert
            }

            ValoreCampoPersonalizzato::updateOrCreate(
                ['socio_id' => $socio->id, 'campo_id' => $campo->id],
                ['valore' => $valore]
            );
        }
    }
}
