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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
 * - accodamento best-effort dell'email "rinnovo_tessera" (solo flusso singolo);
 * - una sola tessera non annullata per socio e anno (vincolo unique,
 *   issue #21): ogni emissione blocca prima la riga del socio e poi il
 *   contatore, ricontrolla sotto lock e tratta la violazione del vincolo
 *   come "già presente".
 */
class TesseraIssuer
{
    public function __construct(private readonly TesseraQrService $qr) {}

    /**
     * Tipo scadenza effettivo dell'associazione (fallback 'solare').
     */
    public function tipoScadenza(Associazione $associazione): string
    {
        $tipo = $associazione->tesseramento()->tipo_scadenza_default;

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
            $contatore = fn () => TesseraCounter::query()
                ->withoutGlobalScope('associazione')
                ->where('associazione_id', $associazioneId)
                ->where('anno', $anno);

            // Prima tessera dell'anno: la riga del contatore nasce con un
            // upsert sul vincolo unique (associazione, anno). Con due emissioni
            // concorrenti la seconda attende la prima invece di fallire per
            // chiave duplicata (o deadlock sul gap lock di un SELECT FOR
            // UPDATE a vuoto), che annullerebbe la transazione del chiamante.
            if (! $contatore()->exists()) {
                TesseraCounter::query()->withoutGlobalScope('associazione')->upsert([[
                    'id' => (string) Str::uuid(),
                    'associazione_id' => $associazioneId,
                    'anno' => $anno,
                    'ultimo_numero' => $this->massimoProgressivoEsistente($associazioneId, $anno),
                ]], ['associazione_id', 'anno'], ['anno']);
            }

            /** @var TesseraCounter $counter */
            $counter = $contatore()->lockForUpdate()->firstOrFail();

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
     * Un socio ha al massimo una tessera NON annullata per anno (vincolo
     * unique socio + anno_non_annullata, issue #21): se esiste già, anche
     * emessa da un processo concorrente, lancia TesseraGiaPresenteException.
     * Una tessera annullata non blocca (duplicato di una tessera persa), e
     * una tessera creata già annullata non entra nel vincolo. I flussi
     * automatici usano emettiSeNonPresente / emettiSeAssente, che trattano
     * la tessera dell'anno (anche annullata) come "già presente" senza errore.
     *
     * @param  array{
     *     anno_validita?: int,
     *     numero_tessera?: string|null,
     *     data_emissione?: string|CarbonInterface|null,
     *     data_scadenza?: string|CarbonInterface|null,
     *     stato?: string,
     *     evento_creazione_id?: string|null,
     * }  $attrs
     *
     * @throws TesseraGiaPresenteException
     * @throws UniqueConstraintViolationException altro vincolo (es. numero tessera manuale già usato)
     */
    public function emetti(Socio $socio, array $attrs = [], bool $accodaEmail = false): Tessera
    {
        $anno = (int) ($attrs['anno_validita'] ?? now()->year);
        $annullata = ($attrs['stato'] ?? Tessera::STATO_ATTIVA) === Tessera::STATO_ANNULLATA;

        return $this->emettiSottoLock($socio, $anno, $attrs, $accodaEmail, conAnnullate: false, controllaEsistente: ! $annullata)
            ?? throw new TesseraGiaPresenteException($anno);
    }

    /**
     * Come emetti(), ma se il socio ha già la tessera dell'anno (qualunque
     * stato) non fa nulla e restituisce null.
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
    public function emettiSeNonPresente(Socio $socio, array $attrs = [], bool $accodaEmail = false): ?Tessera
    {
        return $this->emettiSottoLock($socio, (int) ($attrs['anno_validita'] ?? now()->year), $attrs, $accodaEmail);
    }

    /**
     * Emissione on-demand per il flusso PDF: restituisce la tessera dell'anno
     * corrente del socio (l'ultima emessa) oppure ne crea una nuova.
     */
    public function emettiSeAssente(Socio $socio, ?int $anno = null, ?string $eventoId = null): Tessera
    {
        $anno = $anno ?? (int) now()->year;

        $nuova = $this->emettiSottoLock($socio, $anno, [
            'anno_validita' => $anno,
            'evento_creazione_id' => $eventoId,
        ]);

        if ($nuova !== null) {
            return $nuova;
        }

        // null solo se la riga del socio non esiste più (eliminato nel frattempo)
        $esistente = $this->tesseraDellAnno($socio, $anno)
            ?? throw (new ModelNotFoundException)->setModel(Socio::class, [$socio->id]);

        // Le tessere legacy possono non avere il QR URL persistito
        if (empty($esistente->qr_code_url)) {
            $esistente->qr_code_url = $this->qr->verificationUrl($esistente);
            $esistente->save();
        }

        return $esistente;
    }

    /**
     * Generazione massiva (spec §4.3): una tessera per ogni socio Attivo
     * dell'associazione senza tessera per l'anno indicato (qualunque stato).
     * Nessuna email nel flusso massivo.
     *
     * Una transazione per socio (issue #21), con lo stesso ordine dei lock
     * degli altri flussi (riga del socio → contatore) e il ricontrollo sotto
     * lock di stato del socio e tessera già presente: il lock del contatore
     * dura una sola emissione, quindi un pagamento concorrente di un socio
     * del lotto attende pochi millisecondi invece di andare in deadlock, e
     * una tessera emessa nel frattempo da un altro flusso viene saltata.
     * Un errore a metà lascia le tessere già emesse: rilanciare la
     * generazione completa le mancanti.
     *
     * @return int numero di tessere generate
     */
    public function generaMassivo(Associazione $associazione, int $anno, ?string $eventoId = null): int
    {
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
                    $tessera = $this->emettiSottoLock(
                        $socio,
                        $anno,
                        ['anno_validita' => $anno, 'evento_creazione_id' => $eventoId],
                        idoneo: fn (Socio $bloccato): bool => $bloccato->stato === Socio::STATO_ATTIVO && ! $bloccato->trashed(),
                    );

                    if ($tessera !== null) {
                        $generate++;
                    }
                }
            });

        return $generate;
    }

    /**
     * Tessera dell'anno del socio (l'ultima emessa se ce n'è più d'una:
     * annullate più il duplicato, o doppioni storici). Con $conAnnullate
     * false considera solo le tessere non annullate (quelle del vincolo).
     * Con $bloccante legge con lock condiviso: dentro una transazione
     * InnoDB in REPEATABLE READ una SELECT normale legge lo snapshot della
     * transazione, quella bloccante l'ultima versione committata (SQLite
     * ignora il lock).
     */
    public function tesseraDellAnno(Socio $socio, int $anno, bool $conAnnullate = true, bool $bloccante = false): ?Tessera
    {
        return Tessera::allTenants()
            ->where('socio_id', $socio->id)
            ->where('associazione_id', $socio->associazione_id)
            ->where('anno_validita', $anno)
            ->when(! $conAnnullate, fn ($q) => $q->where('stato', '<>', Tessera::STATO_ANNULLATA))
            ->when($bloccante, fn ($q) => $q->sharedLock())
            ->orderByDesc('data_emissione')
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Emissione con il socio bloccato: lock sulla riga del socio (sempre
     * prima del contatore, stesso ordine in tutti i flussi), ricontrollo
     * della tessera dell'anno e creazione. Restituisce null se la tessera
     * c'è già o se il socio non è più idoneo al ricontrollo sotto lock.
     * $conAnnullate dice se una tessera annullata conta come "già presente"
     * (sì per i flussi automatici, no per la creazione manuale);
     * $controllaEsistente false salta il controllo (tessera creata già
     * annullata, fuori dal vincolo).
     *
     * La violazione del vincolo unique (socio, anno) — un processo che non
     * passa dal lock del socio, o un inserimento concorrente su un database
     * dove il lock non basta — viene trattata come "già presente": il
     * savepoint della transazione annulla anche l'avanzamento del contatore.
     * Se l'emissione gira dentro una transazione esterna (es. pagamento
     * completato), la rilettura dopo la violazione è bloccante: la tessera
     * appena committata dall'altro processo può non essere nello snapshot
     * della transazione esterna.
     *
     * @param  array<string, mixed>  $attrs
     * @param  (callable(Socio): bool)|null  $idoneo
     */
    private function emettiSottoLock(
        Socio $socio,
        int $anno,
        array $attrs,
        bool $accodaEmail = false,
        ?callable $idoneo = null,
        bool $conAnnullate = true,
        bool $controllaEsistente = true,
    ): ?Tessera {
        $attrs['anno_validita'] = $anno;

        try {
            return DB::transaction(function () use ($socio, $anno, $attrs, $accodaEmail, $idoneo, $conAnnullate, $controllaEsistente): ?Tessera {
                /** @var Socio|null $bloccato */
                $bloccato = Socio::query()->withoutGlobalScopes()->whereKey($socio->id)->lockForUpdate()->first();

                if ($bloccato === null || ($idoneo !== null && ! $idoneo($bloccato))) {
                    return null;
                }

                if ($controllaEsistente && $this->tesseraDellAnno($socio, $anno, $conAnnullate) !== null) {
                    return null;
                }

                return $this->crea($socio, $attrs, $accodaEmail);
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            if ($controllaEsistente && $this->tesseraDellAnno($socio, $anno, $conAnnullate, bloccante: DB::transactionLevel() > 0) !== null) {
                return null;
            }

            // Altro vincolo (es. numero tessera manuale già usato)
            throw $e;
        }
    }

    /**
     * Creazione effettiva (chiamata solo da emettiSottoLock, con il socio
     * già bloccato).
     *
     * @param  array<string, mixed>  $attrs
     */
    private function crea(Socio $socio, array $attrs, bool $accodaEmail): Tessera
    {
        /** @var Associazione $associazione */
        $associazione = $socio->associazione()->firstOrFail();

        $anno = (int) $attrs['anno_validita'];
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
                '{DATA_SCADENZA}' => $tessera->data_scadenza->format('d/m/Y'),
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
