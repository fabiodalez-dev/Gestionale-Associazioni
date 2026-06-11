<?php

namespace App\Services\Tessere;

use App\Models\Associazione;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\TesseraTemplate;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generazione del PDF tessera (riusa il layout A4 del legacy
 * pages/genera-tessera-pdf.php: header con logo, tabella dati + QR,
 * box testo template, firme, nota di validità).
 */
class TesseraPdfRenderer
{
    public function __construct(private readonly TesseraQrService $qr) {}

    /**
     * Risposta HTTP con il PDF inline (filename tessera_{numero_socio}.pdf).
     */
    public function render(Tessera $tessera): Response
    {
        /** @var Socio $socio */
        $socio = $tessera->socio()->withoutGlobalScopes()->with(['tipoSocio', 'categoriaSocio'])->firstOrFail();
        /** @var Associazione $associazione */
        $associazione = $tessera->associazione()->firstOrFail();

        $qrUrl = $tessera->qr_code_url ?: $this->qr->verificationUrl($tessera);
        $qrSvg = $this->qr->generateSvg($qrUrl, 3);

        $costo = $socio->tipoSocio?->costo_tessera ?? $associazione->costo_tessera;
        $costoStr = $costo !== null ? '€ '.number_format((float) $costo, 2, ',', '.') : '';

        $testo = $this->testoTessera($tessera, $socio, $associazione, $qrSvg, $qrUrl);

        $pdf = Pdf::loadView('tessere.pdf', [
            'tessera' => $tessera,
            'socio' => $socio,
            'associazione' => $associazione,
            'qrSvg' => $qrSvg,
            'costoStr' => $costoStr,
            'testoTessera' => $testo,
            'logoImg' => $this->logoImg($associazione),
            'dataOggi' => now()->format('d/m/Y'),
            'validoFinoAl' => $tessera->data_scadenza?->format('d/m/Y') ?? '',
        ])
            ->setPaper('a4', 'portrait')
            ->setOption('isRemoteEnabled', true)
            ->setOption('defaultFont', 'DejaVu Sans');

        return $pdf->stream('tessera_'.($socio->numero_socio ?: 'socio').'.pdf');
    }

    /**
     * Testo del corpo tessera con placeholder risolti (spec §6.4).
     * Precedenza: template per tipo socio → template generico (entrambi attivi,
     * fix del legacy che ignorava il flag) → testo_tessera della categoria.
     * Restituisce HTML già escapato (newline → <br>).
     */
    public function testoTessera(Tessera $tessera, Socio $socio, Associazione $associazione, string $qrSvg, string $qrUrl): string
    {
        $template = TesseraTemplate::allTenants()
            ->where('associazione_id', $associazione->id)
            ->where('attivo', true)
            ->where(function ($q) use ($socio) {
                $q->whereNull('tipo_socio_id');
                if ($socio->tipo_socio_id) {
                    $q->orWhere('tipo_socio_id', $socio->tipo_socio_id);
                }
            })
            ->orderByRaw('(tipo_socio_id is null) asc')
            ->first();

        $contenuto = $template?->contenuto ?: $socio->categoriaSocio?->testo_tessera;

        if (empty($contenuto)) {
            return '';
        }

        $rendered = strtr($contenuto, $this->placeholders($tessera, $socio, $associazione, $qrSvg, $qrUrl));

        return nl2br(e($rendered));
    }

    /**
     * @return array<string, string>
     */
    private function placeholders(Tessera $tessera, Socio $socio, Associazione $associazione, string $qrSvg, string $qrUrl): array
    {
        return [
            '{ASSOCIAZIONE_NOME}' => (string) $associazione->nome,
            '{ASSOCIAZIONE_CODICE_FISCALE}' => (string) $associazione->codice_fiscale,
            '{ASSOCIAZIONE_PARTITA_IVA}' => (string) $associazione->partita_iva,
            '{ASSOCIAZIONE_INDIRIZZO}' => (string) $associazione->indirizzo,
            '{ASSOCIAZIONE_CITTA}' => (string) $associazione->citta,
            '{ASSOCIAZIONE_PROVINCIA}' => (string) $associazione->provincia,
            '{ASSOCIAZIONE_CAP}' => (string) $associazione->cap,
            '{ASSOCIAZIONE_EMAIL}' => (string) $associazione->email,
            '{ASSOCIAZIONE_TELEFONO}' => (string) $associazione->telefono,
            '{NOME}' => (string) $socio->nome,
            '{COGNOME}' => (string) $socio->cognome,
            '{NOME_COMPLETO}' => $socio->nome_completo,
            '{NUMERO_SOCIO}' => (string) $socio->numero_socio,
            '{CODICE_FISCALE}' => (string) $socio->codice_fiscale,
            '{INDIRIZZO}' => (string) $socio->indirizzo,
            '{CITTA}' => (string) $socio->citta,
            '{PROVINCIA}' => (string) $socio->provincia,
            '{CAP}' => (string) $socio->cap,
            '{TIPO_SOCIO}' => (string) ($socio->tipoSocio?->nome),
            '{CATEGORIA_SOCIO}' => (string) ($socio->categoriaSocio?->nome),
            '{NUMERO_TESSERA}' => (string) $tessera->numero_tessera,
            '{ANNO_VALIDITA}' => (string) $tessera->anno_validita,
            '{DATA_EMISSIONE}' => now()->format('d/m/Y'),
            '{DATA_SCADENZA}' => $tessera->data_scadenza?->format('d/m/Y') ?? '',
            '{QR_CODE}' => $qrSvg,
            '{QR_URL}' => $qrUrl,
        ];
    }

    /**
     * Tag <img> del logo per il PDF: URL remoto così com'è, path locale
     * convertito in data URI (con protezione path traversal confinata a public/).
     */
    private function logoImg(Associazione $associazione): string
    {
        $logo = $associazione->logo_url;

        if (empty($logo)) {
            return '';
        }

        if (preg_match('~^https?://~i', $logo)) {
            return '<img src="'.e($logo).'" style="height:48px; width:auto;">';
        }

        $root = realpath(public_path());
        $path = realpath(public_path(ltrim($logo, '/')));

        if ($root === false || $path === false || ! is_file($path) || ! str_starts_with($path, $root)) {
            return '';
        }

        $mime = mime_content_type($path) ?: 'image/png';
        $data = base64_encode((string) file_get_contents($path));

        return '<img src="data:'.e($mime).';base64,'.$data.'" style="height:48px; width:auto;">';
    }
}
