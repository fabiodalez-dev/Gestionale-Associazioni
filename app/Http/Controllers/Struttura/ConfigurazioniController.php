<?php

namespace App\Http\Controllers\Struttura;

use App\Http\Controllers\Controller;
use App\Http\Requests\Struttura\SalvaTesseraTemplateRequest;
use App\Http\Requests\Struttura\UpdateCampiObbligatoriRequest;
use App\Http\Requests\Struttura\UpdateInfoAssociazioneRequest;
use App\Http\Requests\Struttura\UpdatePaymentGatewayRequest;
use App\Http\Requests\Struttura\UpdatePrivacyPolicyRequest;
use App\Http\Requests\Struttura\UpdateTemplateEmailRequest;
use App\Http\Requests\Struttura\UpdateTesseramentoRequest;
use App\Http\Requests\Struttura\UploadLogoRequest;
use App\Models\Associazione;
use App\Models\CampoPersonalizzato;
use App\Models\PaymentGatewaySetting;
use App\Models\Sede;
use App\Models\TesseraTemplate;
use App\Models\TipoSocio;
use App\Services\Struttura\LogoAssociazione;
use App\Services\Struttura\RequiredFieldsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Mews\Purifier\Facades\Purifier;

/**
 * Configurazioni del tenant corrente (spec struttura §4).
 * Tutte le rotte passano dal middleware 'tenant'; l'autorizzazione
 * manageSettings è verificata nei Form Request (super_admin/admin_associazione).
 */
class ConfigurazioniController extends Controller
{
    /** Whitelist HTML coerente con la toolbar TinyMCE legacy (spec §9.10). */
    private const PRIVACY_HTML_ALLOWED =
        'p,h1,h2,h3,b,strong,i,em,u,ul,ol,li,a[href|title|target],table,thead,tbody,tr,td,th,br,span,div';

    public function __construct(
        private readonly RequiredFieldsService $requiredFields,
        private readonly LogoAssociazione $logo,
    ) {}

    public function index(Request $request): View
    {
        $associazione = $this->associazioneCorrente();
        Gate::authorize('manageSettings', $associazione);

        $tipoSelezionato = $request->query('tipo_id') ?: null;

        $tesseraTemplate = TesseraTemplate::query()
            ->forAssociazione($associazione->id)
            ->when(
                $tipoSelezionato,
                fn ($q) => $q->where('tipo_socio_id', $tipoSelezionato),
                fn ($q) => $q->whereNull('tipo_socio_id')
            )
            ->first();

        return view('struttura.configurazioni.index', [
            'associazione' => $associazione,
            'section' => $request->query('section', 'info'),
            'sedi' => Sede::query()->forAssociazione($associazione->id)->orderBy('nome')->get(),
            'tipiSocio' => TipoSocio::query()->forAssociazione($associazione->id)->orderBy('nome')->get(),
            'tipoSelezionato' => $tipoSelezionato,
            'tesseraTemplate' => $tesseraTemplate,
            'requiredConfig' => $this->requiredFields->load($associazione),
            'campiPersonalizzati' => CampoPersonalizzato::query()
                ->forAssociazione($associazione->id)
                ->orderBy('ordine')
                ->orderBy('nome_campo')
                ->get(),
            'paymentSettings' => PaymentGatewaySetting::query()
                ->forAssociazione($associazione->id)
                ->first(),
            'labels' => RequiredFieldsService::LABELS,
            'backendFields' => RequiredFieldsService::BACKEND_FIELDS,
            'preiscrizioneFields' => RequiredFieldsService::PREISCRIZIONE_FIELDS,
        ]);
    }

    /**
     * Dati anagrafici (POST update_info legacy). Non tocca attiva/costo_tessera.
     */
    public function updateInfo(UpdateInfoAssociazioneRequest $request): RedirectResponse
    {
        $request->associazioneCorrente()->update($request->validated());

        return $this->redirectToSection('info', 'Dati anagrafici aggiornati con successo.');
    }

    /**
     * Upload logo: qui l'errore di validazione è bloccante (spec §4.2.2).
     */
    public function uploadLogo(UploadLogoRequest $request): RedirectResponse
    {
        $associazione = $request->associazioneCorrente();

        $this->logo->salva($associazione, $request->file('logo_file'));

        return $this->redirectToSection('logo', 'Logo aggiornato con successo.');
    }

    public function updateTesseramento(UpdateTesseramentoRequest $request): RedirectResponse
    {
        $request->associazioneCorrente()->tesseramento()->aggiorna($request->validated());

        return $this->redirectToSection('tesseramento', 'Impostazioni tesseramento aggiornate.');
    }

    public function updateTemplateEmail(UpdateTemplateEmailRequest $request): RedirectResponse
    {
        $request->associazioneCorrente()->tesseramento()->aggiorna([
            'template_email_scadenza' => $request->validated('template_email_scadenza'),
        ]);

        return $this->redirectToSection('template-email', 'Template email scadenza aggiornato.');
    }

    /**
     * Privacy policy: HTML sanitizzato in scrittura con purifier
     * (irrobustimento rispetto al raw legacy — spec §4.2.6, §9.10).
     */
    public function updatePrivacyPolicy(UpdatePrivacyPolicyRequest $request): RedirectResponse
    {
        $html = (string) $request->validated('privacy_policy_html', '');

        $request->associazioneCorrente()->update([
            'privacy_policy_html' => $html === ''
                ? null
                : Purifier::clean($html, ['HTML.Allowed' => self::PRIVACY_HTML_ALLOWED]),
        ]);

        return $this->redirectToSection('privacy', 'Privacy policy aggiornata.');
    }

    /**
     * Campi obbligatori backend/preiscrizione + flag dei campi personalizzati (spec §4.2.7).
     */
    public function updateCampiObbligatori(UpdateCampiObbligatoriRequest $request): RedirectResponse
    {
        $associazione = $request->associazioneCorrente();

        $config = $this->requiredFields->buildConfig(
            (array) $request->input('required_backend', []),
            (array) $request->input('required_preiscrizione', [])
        );

        $associazione->iscrizioni()->aggiorna(['campi_obbligatori_config' => $config]);

        // Flag obbligatorietà dei campi personalizzati (UPDATE scoped per tenant)
        $customBackend = array_values((array) $request->input('required_custom_backend', []));
        $customPreiscrizione = array_values((array) $request->input('required_custom_preiscrizione', []));

        CampoPersonalizzato::query()
            ->forAssociazione($associazione->id)
            ->get()
            ->each(function (CampoPersonalizzato $campo) use ($customBackend, $customPreiscrizione): void {
                $campo->update([
                    'obbligatorio' => in_array($campo->id, $customBackend, true),
                    'obbligatorio_preiscrizione' => in_array($campo->id, $customPreiscrizione, true),
                ]);
            });

        return $this->redirectToSection('campi-obbligatori', 'Configurazione campi obbligatori salvata.');
    }

    /**
     * Template tessera per tipo socio: upsert per (associazione, tipo|NULL) (spec §4.2.8).
     */
    public function saveTesseraTemplate(SalvaTesseraTemplateRequest $request): RedirectResponse
    {
        $associazione = $request->associazioneCorrente();
        $tipoId = $request->validated('tipo_id');

        $template = TesseraTemplate::query()
            ->forAssociazione($associazione->id)
            ->when(
                $tipoId,
                fn ($q) => $q->where('tipo_socio_id', $tipoId),
                fn ($q) => $q->whereNull('tipo_socio_id')
            )
            ->first();

        if ($template) {
            $template->update(['contenuto' => $request->validated('contenuto')]);
        } else {
            TesseraTemplate::create([
                'associazione_id' => $associazione->id,
                'tipo_socio_id' => $tipoId,
                'titolo' => 'Template Tessera',
                'contenuto' => $request->validated('contenuto'),
                'attivo' => true,
            ]);
        }

        return redirect()
            ->route('configurazioni.index', array_filter(['section' => 'tessera-template', 'tipo_id' => $tipoId]))
            ->with('success', 'Template tessera salvato.');
    }

    /**
     * Pagamenti online Stripe/PayPal (spec §4.2.9).
     * Segreti: input vuoto = mantieni il valore cifrato esistente.
     */
    public function updatePaymentGateway(UpdatePaymentGatewayRequest $request): RedirectResponse
    {
        $associazione = $request->associazioneCorrente();
        $dati = $request->validated();

        $settings = PaymentGatewaySetting::query()
            ->forAssociazione($associazione->id)
            ->first() ?? new PaymentGatewaySetting(['associazione_id' => $associazione->id]);

        $settings->fill([
            'associazione_id' => $associazione->id,
            'stripe_enabled' => $request->boolean('stripe_enabled'),
            'stripe_publishable_key' => $dati['stripe_publishable_key'] ?? '',
            'paypal_enabled' => $request->boolean('paypal_enabled'),
            'paypal_client_id' => $dati['paypal_client_id'] ?? '',
            'paypal_mode' => $dati['paypal_mode'] ?? 'sandbox',
            'paypal_webhook_id' => $dati['paypal_webhook_id'] ?? '',
            'auto_attivazione_pagamento' => $request->boolean('auto_attivazione_pagamento'),
            'valuta' => strtoupper($dati['valuta'] ?? 'EUR'),
        ]);

        // Segreti: sovrascritti (cifrati dal cast 'encrypted') solo se valorizzati
        if (filled($dati['stripe_secret_key'] ?? null)) {
            $settings->stripe_secret_key_encrypted = $dati['stripe_secret_key'];
        }
        if (filled($dati['stripe_webhook_secret'] ?? null)) {
            $settings->stripe_webhook_secret_encrypted = $dati['stripe_webhook_secret'];
        }
        if (filled($dati['paypal_client_secret'] ?? null)) {
            $settings->paypal_client_secret_encrypted = $dati['paypal_client_secret'];
        }

        $settings->save();

        return $this->redirectToSection('pagamenti', 'Impostazioni pagamento salvate.');
    }

    private function associazioneCorrente(): Associazione
    {
        return Associazione::findOrFail(session('associazione_id'));
    }

    private function redirectToSection(string $section, string $message): RedirectResponse
    {
        return redirect()
            ->route('configurazioni.index', ['section' => $section])
            ->with('success', $message);
    }
}
