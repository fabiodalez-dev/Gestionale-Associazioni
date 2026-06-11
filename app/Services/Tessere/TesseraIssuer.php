<?php

namespace App\Services\Tessere;

use App\Models\Associazione;
use App\Models\EmailQueue;
use App\Models\EmailTemplate;
use App\Models\SmtpSetting;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\TesseraCounter;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Servizio unico di emissione/rinnovo tessere (spec §10 "TesseraIssuer").
 *
 * Centralizza per tutti i flussi (creazione singola, generazione massiva,
 * emissione on-demand per il PDF):
 * - calcolo scadenza solare/annuale dal default associazione;
 * - numerazione unificata {anno}{progressivo 4 cifre} concorrenza-safe
 *   (riga contatore con lockForUpdate in transazione);
 * - etichetta template, evento opzionale, stato iniziale;
 * - persistenza di qr_code_url al momento dell'emissione;
 * - accodamento best-effort dell'email "rinnovo_tessera" (solo flusso singolo).
 */
class TesseraIssuer
{
    public function __construct(private readonly TesseraQrService $qr) {}

    /**
     * Tipo scadenza effettivo dell'associazione (fallback 'solare').
     */
    public function tipoScadenza(Associazione $associazione): string
    {
        $tipo = $associazione->tipo_scadenza_default;

        return in_array($tipo, [Tessera::TIPO_SCADENZA_SOLARE, Tessera::TIPO_SCADENZA_ANNUALE], true)
            ? $tipo
            : Tessera::TIPO_SCADENZA_SOLARE;
    }

    /**
     * Calcola la data di scadenza secondo il tipo (spec §5):
     * - solare: 31/12 dell'anno di validità;
     * - annuale: data emissione + 1 anno.
     */
    public function calcolaScadenza(string $tipoScadenza, int $annoValidita, CarbonInterface $dataEmissione): Carbon
    {
        if ($tipoScadenza === Tessera::TIPO_SCADENZA_ANNUALE) {
            return Carbon::parse($dataEmissione)->addYear();
        }

        return Carbon::create($annoValidita, 12, 31);
    }

    /**
     * Prossimo numero tessera per (associazione, anno) nel formato {anno}{NNNN}.
     * Concorrenza-safe: contatore con lockForUpdate, inizializzato dal massimo
     * progressivo già esistente (compatibilità con i dati legacy).
     */
    public function prossimoNumero(string $associazioneId, int $anno): string
    {
        return DB::transaction(function () use ($associazioneId, $anno): string {
            $counter = TesseraCounter::query()
                ->withoutGlobalScope('associazione')
                ->where('associazione_id', $associazioneId)
                ->where('anno', $anno)
                ->lockForUpdate()
                ->first();

            if ($counter === null) {
                $counter = new TesseraCounter([
                    'associazione_id' => $associazioneId,
                    'anno' => $anno,
                    'ultimo_numero' => $this->massimoProgressivoEsistente($associazioneId, $anno),
                ]);
            }

            do {
                $counter->ultimo_numero++;
                $numero = $anno.str_pad((string) $counter->ultimo_numero, 4, '0', STR_PAD_LEFT);
                $esiste = Tessera::allTenants()
                    ->where('associazione_id', $associazioneId)
                    ->where('numero_tessera', $numero)
                    ->exists();
            } while ($esiste);

            $counter->save();

            return $numero;
        });
    }

    /**
     * Massimo progressivo {anno}NNNN già presente per l'anno (dati pre-contatore).
     */
    private function massimoProgressivoEsistente(string $associazioneId, int $anno): int
    {
        $max = 0;

        Tessera::allTenants()
            ->where('associazione_id', $associazioneId)
            ->where('anno_validita', $anno)
            ->pluck('numero_tessera')
            ->each(function (string $numero) use ($anno, &$max): void {
                if (preg_match('/^'.$anno.'(\d{4})$/', $numero, $m)) {
                    $max = max($max, (int) $m[1]);
                }
            });

        return $max;
    }

    /**
     * Creazione di una tessera per un socio. Usata da tutti i flussi.
     *
     * @param  array{
     *     anno_validita?: int,
     *     numero_tessera?: string|null,
     *     data_emissione?: string|CarbonInterface|null,
     *     data_scadenza?: string|CarbonInterface|null,
     *     stato?: string,
     *     evento_creazione_id?: string|null,
     * }  $attrs
     */
    public function emetti(Socio $socio, array $attrs = [], bool $accodaEmail = false): Tessera
    {
        /** @var Associazione $associazione */
        $associazione = $socio->associazione()->firstOrFail();

        $anno = (int) ($attrs['anno_validita'] ?? now()->year);
        $tipoScadenza = $this->tipoScadenza($associazione);

        $dataEmissione = ! empty($attrs['data_emissione'])
            ? Carbon::parse($attrs['data_emissione'])
            : now();

        $dataScadenza = ! empty($attrs['data_scadenza'])
            ? Carbon::parse($attrs['data_scadenza'])
            : $this->calcolaScadenza($tipoScadenza, $anno, $dataEmissione);

        $numero = $attrs['numero_tessera'] ?? null;
        if ($numero === null || $numero === '') {
            $numero = $this->prossimoNumero($associazione->id, $anno);
        }

        $tessera = new Tessera([
            'socio_id' => $socio->id,
            'associazione_id' => $associazione->id,
            'numero_tessera' => $numero,
            'anno_validita' => $anno,
            'data_emissione' => $dataEmissione->toDateString(),
            'data_scadenza' => $dataScadenza->toDateString(),
            'tipo_scadenza' => $tipoScadenza,
            'stato' => $attrs['stato'] ?? Tessera::STATO_ATTIVA,
            'evento_creazione_id' => $attrs['evento_creazione_id'] ?? null,
            // Etichetta denormalizzata del tipo tessera (spec §6.1)
            'template_tessera' => $socio->tipoSocio?->nome ?: 'Default',
        ]);

        $tessera->save();

        // qr_code_url persistito all'emissione (non alla stampa, spec §10)
        $tessera->qr_code_url = $this->qr->verificationUrl($tessera);
        $tessera->save();

        if ($accodaEmail) {
            $this->accodaEmailRinnovo($tessera, $socio, $associazione);
        }

        return $tessera;
    }

    /**
     * Emissione on-demand per il flusso PDF: restituisce la tessera dell'anno
     * corrente del socio (l'ultima emessa) oppure ne crea una nuova.
     */
    public function emettiSeAssente(Socio $socio, ?int $anno = null, ?string $eventoId = null): Tessera
    {
        $anno = $anno ?? (int) now()->year;

        $esistente = Tessera::allTenants()
            ->where('socio_id', $socio->id)
            ->where('associazione_id', $socio->associazione_id)
            ->where('anno_validita', $anno)
            ->orderByDesc('data_emissione')
            ->first();

        if ($esistente !== null) {
            // Le tessere legacy possono non avere il QR URL persistito
            if (empty($esistente->qr_code_url)) {
                $esistente->qr_code_url = $this->qr->verificationUrl($esistente);
                $esistente->save();
            }

            return $esistente;
        }

        return $this->emetti($socio, [
            'anno_validita' => $anno,
            'evento_creazione_id' => $eventoId,
        ]);
    }

    /**
     * Generazione massiva (spec §4.3): una tessera per ogni socio Attivo
     * dell'associazione senza tessera per l'anno indicato (qualunque stato).
     * In transazione; nessuna email nel flusso massivo.
     *
     * @return int numero di tessere generate
     */
    public function generaMassivo(Associazione $associazione, int $anno, ?string $eventoId = null): int
    {
        return DB::transaction(function () use ($associazione, $anno, $eventoId): int {
            $generate = 0;

            Socio::query()
                ->forAssociazione($associazione->id)
                ->where('stato', Socio::STATO_ATTIVO)
                ->whereNotExists(function ($q) use ($associazione, $anno) {
                    $q->selectRaw('1')
                        ->from('tessere')
                        ->whereColumn('tessere.socio_id', 'soci.id')
                        ->where('tessere.associazione_id', $associazione->id)
                        ->where('tessere.anno_validita', $anno);
                })
                ->with('tipoSocio')
                ->chunkById(200, function ($soci) use ($anno, $eventoId, &$generate): void {
                    foreach ($soci as $socio) {
                        $this->emetti($socio, [
                            'anno_validita' => $anno,
                            'evento_creazione_id' => $eventoId,
                        ]);
                        $generate++;
                    }
                });

            return $generate;
        });
    }

    /**
     * Accoda l'email "rinnovo_tessera" (best-effort, spec §4.5).
     * Condizioni: SMTP configurato con auto_rinnovo_tessera attivo,
     * template 'rinnovo_tessera' attivo, socio con email.
     */
    public function accodaEmailRinnovo(Tessera $tessera, ?Socio $socio = null, ?Associazione $associazione = null): void
    {
        try {
            $socio = $socio ?? $tessera->socio()->first();
            if ($socio === null || empty($socio->email)) {
                return;
            }

            $associazione = $associazione ?? $tessera->associazione()->first();
            if ($associazione === null) {
                return;
            }

            $smtp = SmtpSetting::allTenants()
                ->where('associazione_id', $associazione->id)
                ->first();

            if ($smtp === null || ! $smtp->auto_rinnovo_tessera) {
                return;
            }

            $template = EmailTemplate::allTenants()
                ->where('associazione_id', $associazione->id)
                ->where('codice', 'rinnovo_tessera')
                ->where('attivo', true)
                ->first();

            if ($template === null) {
                return;
            }

            $placeholders = [
                '{NOME}' => $socio->nome,
                '{COGNOME}' => $socio->cognome,
                '{NOME_COMPLETO}' => $socio->nome_completo,
                '{NUMERO_SOCIO}' => (string) $socio->numero_socio,
                '{EMAIL}' => (string) $socio->email,
                '{ASSOCIAZIONE_NOME}' => (string) $associazione->nome,
                '{NUMERO_TESSERA}' => (string) $tessera->numero_tessera,
                '{ANNO_VALIDITA}' => (string) $tessera->anno_validita,
                '{DATA_SCADENZA}' => $tessera->data_scadenza?->format('d/m/Y') ?? '',
            ];

            EmailQueue::allTenants()->create([
                'associazione_id' => $associazione->id,
                'socio_id' => $socio->id,
                'to_email' => $socio->email,
                'to_name' => $socio->nome_completo,
                'subject' => strtr((string) $template->oggetto, $placeholders),
                'body_html' => strtr((string) $template->corpo_html, $placeholders),
                'template_codice' => 'rinnovo_tessera',
                'priority' => 3,
                'status' => EmailQueue::STATUS_PENDING,
            ]);
        } catch (\Throwable $e) {
            // Best-effort: la tessera resta valida anche se l'accodamento fallisce
            report($e);
        }
    }
}
