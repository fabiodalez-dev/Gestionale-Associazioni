<?php

namespace App\Http\Controllers\Struttura;

use App\Http\Controllers\Controller;
use App\Http\Requests\Struttura\PreiscrizioneRequest;
use App\Mail\Struttura\NuovaPreiscrizioneMail;
use App\Models\Associazione;
use App\Models\CampoPersonalizzato;
use App\Models\CategoriaSocio;
use App\Models\Socio;
use App\Models\StoricoAttivitaSocio;
use App\Models\TipoSocio;
use App\Models\ValoreCampoPersonalizzato;
use App\Services\Struttura\NumeroSocioGenerator;
use App\Services\Struttura\RequiredFieldsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

/**
 * Pre-iscrizione pubblica e rinnovo dati (spec struttura §6).
 *
 * URL: /preiscrizione/{uuid associazione} (+ ?token=<64 hex> per il rinnovo).
 * Nessun login; CSRF + honeypot (spatie, miglioramento 6) + rate limiting.
 */
class PreiscrizioneController extends Controller
{
    private const UUID_REGEX = '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i';

    private const TOKEN_REGEX = '/^[a-f0-9]{64}$/i';

    public function __construct(
        private readonly RequiredFieldsService $requiredFields,
        private readonly NumeroSocioGenerator $numeroSocio,
    ) {}

    public function show(Request $request, string $associazione): View
    {
        $assoc = $this->risolviAssociazione($associazione);

        $token = (string) $request->query('token', '');
        $socioRinnovo = null;
        $tokenScaduto = false;

        if ($token !== '') {
            $socioRinnovo = $this->socioPerToken($assoc, $token);
            $tokenScaduto = $socioRinnovo === null;
        }

        return view('struttura.preiscrizione.show', array_merge(
            $this->datiForm($assoc),
            [
                'socioRinnovo' => $socioRinnovo,
                'tokenScaduto' => $tokenScaduto,
                'renewalToken' => $socioRinnovo ? $token : null,
                'valoriCustom' => $socioRinnovo
                    ? $socioRinnovo->valoriCampiPersonalizzati->pluck('valore', 'campo_id')->all()
                    : [],
            ]
        ));
    }

    public function store(PreiscrizioneRequest $request, string $associazione): RedirectResponse
    {
        $assoc = $this->risolviAssociazione($associazione);
        $dati = $request->validated();

        // Anti-tamper FK: tipo/categoria devono appartenere al tenant, altrimenti NULL silenzioso
        $tipoSocioId = $this->fkDelTenant(TipoSocio::class, $dati['tipo_socio_id'] ?? null, $assoc->id);
        $categoriaId = $this->fkDelTenant(CategoriaSocio::class, $dati['categoria_socio_id'] ?? null, $assoc->id);

        // Percorso RINNOVO: token POST riverificato (formato + lookup + scadenza)
        $renewalToken = (string) ($dati['renewal_token'] ?? '');
        if ($renewalToken !== '') {
            $socio = $this->socioPerToken($assoc, $renewalToken);

            if (! $socio) {
                return back()->withInput()->with('error', 'Link di rinnovo scaduto o non valido. Contatta l\'associazione a '.$assoc->email.'.');
            }

            return $this->aggiornaSocio($request, $assoc, $socio, $dati, $tipoSocioId, $categoriaId);
        }

        return $this->nuovaPreiscrizione($request, $assoc, $dati, $tipoSocioId, $categoriaId);
    }

    private function nuovaPreiscrizione(Request $request, Associazione $assoc, array $dati, ?string $tipoSocioId, ?string $categoriaId): RedirectResponse
    {
        // Controllo duplicati: email, poi codice fiscale (spec §6.7.1)
        $esistente = Socio::query()->forAssociazione($assoc->id)
            ->where('email', $dati['email'])
            ->first();

        if (! $esistente && filled($dati['codice_fiscale'] ?? null)) {
            $esistente = Socio::query()->forAssociazione($assoc->id)
                ->whereRaw('UPPER(codice_fiscale) = ?', [strtoupper($dati['codice_fiscale'])])
                ->first();
        }

        if ($esistente) {
            $campo = strcasecmp($esistente->email, $dati['email']) === 0 ? 'email' : 'codice fiscale';

            return back()->withInput()->with(
                'error',
                "Esiste già un socio registrato con questo {$campo} ({$esistente->nome_completo}). ".
                'Se hai bisogno di assistenza, contatta l\'associazione.'
            );
        }

        try {
            $socio = DB::transaction(function () use ($request, $assoc, $dati, $tipoSocioId, $categoriaId): Socio {
                $socio = Socio::create([
                    'associazione_id' => $assoc->id,
                    'numero_socio' => $this->numeroSocio->next($assoc->id),
                    'nome' => $dati['nome'],
                    'cognome' => $dati['cognome'],
                    'email' => $dati['email'],
                    'telefono' => $dati['telefono'] ?? null,
                    'data_nascita' => $dati['data_nascita'] ?? null,
                    'codice_fiscale' => $dati['codice_fiscale'] ?? null,
                    'indirizzo' => $dati['indirizzo'] ?? null,
                    'citta' => $dati['citta'] ?? null,
                    'provincia' => $dati['provincia'] ?? null,
                    'cap' => $dati['cap'] ?? null,
                    'data_iscrizione' => now()->toDateString(),
                    'stato' => Socio::STATO_IN_ATTESA,
                    'privacy_consenso' => true, // forzato server-side (GDPR)
                    'tipo_socio_id' => $tipoSocioId,
                    'categoria_socio_id' => $categoriaId,
                ]);

                $this->salvaCampiPersonalizzati($assoc, $socio, (array) $request->input('custom_field', []), soloNonVuoti: true);

                StoricoAttivitaSocio::create([
                    'associazione_id' => $assoc->id,
                    'socio_id' => $socio->id,
                    'tipo_attivita' => 'Pre-iscrizione',
                    'descrizione' => 'Richiesta di pre-iscrizione inviata dal form pubblico.',
                    'data_attivita' => now(),
                ]);

                return $socio;
            });
        } catch (\Throwable $e) {
            Log::error('Pre-iscrizione fallita: '.$e->getMessage());

            return back()->withInput()->with('error', 'Si è verificato un errore durante l\'invio della richiesta. Riprova più tardi.');
        }

        // Notifica all'associazione: best-effort, un errore non blocca l'iscrizione (spec §6.7.6)
        try {
            Mail::to($assoc->email)->queue(new NuovaPreiscrizioneMail($assoc, $socio));
        } catch (\Throwable $e) {
            Log::warning('Notifica nuova preiscrizione non inviata: '.$e->getMessage());
        }

        return redirect()
            ->route('preiscrizione.show', ['associazione' => $assoc->id])
            ->with('success', 'La tua richiesta di pre-iscrizione è stata inviata con successo! Riceverai una comunicazione quando sarà approvata.');
    }

    private function aggiornaSocio(Request $request, Associazione $assoc, Socio $socio, array $dati, ?string $tipoSocioId, ?string $categoriaId): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $assoc, $socio, $dati, $tipoSocioId, $categoriaId): void {
                $socio->update([
                    'nome' => $dati['nome'],
                    'cognome' => $dati['cognome'],
                    'email' => $dati['email'],
                    'telefono' => $dati['telefono'] ?? null,
                    'data_nascita' => $dati['data_nascita'] ?? null,
                    'codice_fiscale' => $dati['codice_fiscale'] ?? null,
                    'indirizzo' => $dati['indirizzo'] ?? null,
                    'citta' => $dati['citta'] ?? null,
                    'provincia' => $dati['provincia'] ?? null,
                    'cap' => $dati['cap'] ?? null,
                    'tipo_socio_id' => $tipoSocioId,
                    'categoria_socio_id' => $categoriaId,
                    'privacy_consenso' => true,
                ]);

                // Token one-shot: azzerato dopo l'uso (spec §6.6).
                // forceFill: i campi token non sono fillable per sicurezza.
                $socio->forceFill([
                    'rinnovo_token' => null,
                    'rinnovo_token_expires' => null,
                ])->save();

                $this->salvaCampiPersonalizzati($assoc, $socio, $request->input('custom_field', []), soloNonVuoti: false);

                StoricoAttivitaSocio::create([
                    'associazione_id' => $assoc->id,
                    'socio_id' => $socio->id,
                    'tipo_attivita' => 'Rinnovo dati',
                    'descrizione' => 'Dati anagrafici aggiornati tramite form di rinnovo.',
                    'data_attivita' => now(),
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('Rinnovo dati socio fallito: '.$e->getMessage());

            return back()->withInput()->with('error', 'Si è verificato un errore durante l\'aggiornamento dei dati. Riprova più tardi.');
        }

        return redirect()
            ->route('preiscrizione.show', ['associazione' => $assoc->id])
            ->with('success', 'I tuoi dati sono stati aggiornati con successo. Grazie!');
    }

    /**
     * Upsert valori campi personalizzati (spec §6.6/§6.7):
     * - rinnovo: UPDATE anche a stringa vuota se il valore esiste, INSERT solo se non vuoto;
     * - nuova iscrizione: INSERT solo dei valori non vuoti.
     *
     * @param  array<string, mixed>  $valori
     */
    private function salvaCampiPersonalizzati(Associazione $assoc, Socio $socio, array $valori, bool $soloNonVuoti): void
    {
        $campiValidi = CampoPersonalizzato::query()
            ->forAssociazione($assoc->id)
            ->pluck('id')
            ->all();

        foreach ($valori as $campoId => $valore) {
            if (! in_array($campoId, $campiValidi, true)) {
                continue; // anti-tamper: il campo deve appartenere al tenant
            }

            $valore = is_scalar($valore) ? trim((string) $valore) : '';

            $esistente = ValoreCampoPersonalizzato::query()
                ->where('socio_id', $socio->id)
                ->where('campo_id', $campoId)
                ->first();

            if ($esistente && ! $soloNonVuoti) {
                // Rinnovo: il valore esistente si aggiorna anche a stringa vuota
                $esistente->update(['valore' => $valore]);
            } elseif ($esistente) {
                if ($valore !== '') {
                    $esistente->update(['valore' => $valore]);
                }
            } elseif ($valore !== '') {
                ValoreCampoPersonalizzato::create([
                    'socio_id' => $socio->id,
                    'campo_id' => $campoId,
                    'valore' => $valore,
                ]);
            }
        }
    }

    /**
     * Validazione accesso pubblica (spec §6.1):
     * UUID malformato → 400; associazione inesistente o non attiva → 404.
     */
    private function risolviAssociazione(string $id): Associazione
    {
        abort_unless(preg_match(self::UUID_REGEX, $id) === 1, 400, 'Link non valido.');

        $assoc = Associazione::query()
            ->where('id', strtolower($id))
            ->where('attiva', true)
            ->first();

        abort_unless($assoc !== null, 404, 'Associazione non trovata.');

        return $assoc;
    }

    private function socioPerToken(Associazione $assoc, string $token): ?Socio
    {
        if (preg_match(self::TOKEN_REGEX, $token) !== 1) {
            return null;
        }

        $socio = Socio::query()
            ->forAssociazione($assoc->id)
            ->where('rinnovo_token', $token)
            ->first();

        if (! $socio || ! $socio->rinnovo_token_expires || $socio->rinnovo_token_expires->isPast()) {
            return null;
        }

        return $socio;
    }

    private function fkDelTenant(string $modello, ?string $id, string $associazioneId): ?string
    {
        if (blank($id)) {
            return null;
        }

        return $modello::query()->forAssociazione($associazioneId)->whereKey($id)->exists()
            ? $id
            : null; // azzerato silenziosamente (spec §6.5)
    }

    /**
     * @return array<string, mixed>
     */
    private function datiForm(Associazione $assoc): array
    {
        return [
            'associazione' => $assoc,
            'tipiSocio' => TipoSocio::query()->forAssociazione($assoc->id)->orderBy('nome')->get(),
            'categorieSocio' => CategoriaSocio::query()->forAssociazione($assoc->id)->orderBy('nome')->get(),
            'campiPersonalizzati' => CampoPersonalizzato::query()
                ->forAssociazione($assoc->id)
                ->orderBy('ordine')
                ->orderBy('nome_campo')
                ->get(),
            'requiredConfig' => $this->requiredFields->load($assoc),
        ];
    }
}
