<?php

namespace Tests\Feature\quote_pagamenti;

use App\Models\Quota;
use App\Models\Ricevuta;
use App\Models\Socio;
use App\Services\Pagamenti\RicevutaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Issue #20: la ricevuta salva all'emissione intestatario e dati del
 * pagamento; il PDF stampa l'istantanea anche se poi socio o quota vengono
 * corretti, e ricade sui dati correnti per le ricevute storiche.
 */
class RicevutaIstantaneaTest extends TestCase
{
    use ConfiguraTenantQuote;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configuraTenant();
        Notification::fake();
    }

    private function socioDiProva(): Socio
    {
        return $this->creaSocio([
            'nome' => 'Maria',
            'cognome' => 'Rossi',
            'codice_fiscale' => 'RSSMRA80A41H501U',
            'numero_socio' => '42',
        ]);
    }

    private function quotaPagata(Socio $socio): Quota
    {
        return $this->creaQuota($socio, [
            'anno' => 2026,
            'importo' => 30,
            'tipo' => 'Quota Ordinaria',
            'data_pagamento' => '2026-03-10',
            'metodo_pagamento' => 'bonifico',
            'gateway_transaction_id' => 'TRN-001',
        ]);
    }

    /**
     * HTML della ricevuta come lo produce RicevutaService::pdfContent
     * (stessi dati passati al template, catturati dal view composer).
     */
    private function htmlRicevuta(Ricevuta $ricevuta): string
    {
        $dati = null;
        View::composer('email-conferme.ricevuta-pdf', function ($view) use (&$dati): void {
            $dati ??= $view->getData();
        });

        app(RicevutaService::class)->pdfContent($ricevuta->fresh());

        $this->assertIsArray($dati, 'Il template della ricevuta non è stato renderizzato');

        return view('email-conferme.ricevuta-pdf', $dati)->render();
    }

    public function test_l_emissione_salva_l_istantanea_di_intestatario_e_pagamento(): void
    {
        $quota = $this->quotaPagata($this->socioDiProva());

        $ricevuta = app(RicevutaService::class)->emettiPerQuota($quota);

        $this->assertNotNull($ricevuta);
        $ricevuta->refresh();
        $this->assertTrue($ricevuta->haIstantanea());
        $this->assertSame('Maria', $ricevuta->intestatario_nome);
        $this->assertSame('Rossi', $ricevuta->intestatario_cognome);
        $this->assertSame('RSSMRA80A41H501U', $ricevuta->intestatario_codice_fiscale);
        $this->assertSame('42', $ricevuta->numero_socio);
        $this->assertSame('Quota Ordinaria', $ricevuta->tipo_quota);
        $this->assertSame('bonifico', $ricevuta->metodo_pagamento);
        $this->assertSame('2026-03-10', $ricevuta->data_pagamento?->toDateString());
        $this->assertSame('TRN-001', $ricevuta->riferimento_transazione);
    }

    public function test_il_pdf_resta_quello_emesso_dopo_la_correzione_di_socio_e_quota(): void
    {
        $socio = $this->socioDiProva();
        $quota = $this->quotaPagata($socio);
        $ricevuta = app(RicevutaService::class)->emettiPerQuota($quota);

        // Correzioni successive all'emissione: anagrafica del socio, tipo e
        // metodo della quota, e perfino il socio a cui è intestata la quota
        $socio->update(['nome' => 'Mariangela', 'cognome' => 'Bianchi', 'codice_fiscale' => 'BNCMNG80A41H501X', 'numero_socio' => '99']);
        $altro = $this->creaSocio(['nome' => 'Giulio', 'cognome' => 'Verdi']);
        $quota->update(['tipo' => 'Quota Sostenitore', 'metodo_pagamento' => 'contanti', 'gateway_transaction_id' => null, 'socio_id' => $altro->id]);

        $html = $this->htmlRicevuta($ricevuta);

        $this->assertStringContainsString('Maria Rossi', $html);
        $this->assertStringContainsString('socio n. 42', $html);
        $this->assertStringContainsString('RSSMRA80A41H501U', $html);
        $this->assertStringContainsString('Quota Ordinaria', $html);
        $this->assertStringContainsString('Bonifico', $html);
        $this->assertStringContainsString('10/03/2026', $html);
        $this->assertStringContainsString('TRN-001', $html);
        $this->assertStringNotContainsString('Bianchi', $html);
        $this->assertStringNotContainsString('Verdi', $html);
        $this->assertStringNotContainsString('Quota Sostenitore', $html);
    }

    public function test_ricevuta_storica_senza_istantanea_usa_i_dati_correnti(): void
    {
        $socio = $this->socioDiProva();
        $quota = $this->quotaPagata($socio);

        // Ricevuta emessa prima della migration: nessuna istantanea
        $ricevuta = Ricevuta::allTenants()->create([
            'associazione_id' => $this->associazione->id,
            'quota_id' => $quota->id,
            'numero' => 1,
            'anno' => 2026,
            'importo' => 30,
            'emessa_at' => now(),
        ]);
        $this->assertFalse($ricevuta->haIstantanea());

        $socio->update(['cognome' => 'Bianchi']);

        $html = $this->htmlRicevuta($ricevuta);

        $this->assertStringContainsString('Maria Bianchi', $html);
        $this->assertStringContainsString('socio n. 42', $html);
        $this->assertStringContainsString('Quota Ordinaria', $html);
        $this->assertStringContainsString('Bonifico', $html);
    }

    public function test_istantanea_senza_intestatario_conserva_comunque_i_dati_della_quota(): void
    {
        $socio = $this->socioDiProva();
        $quota = $this->quotaPagata($socio);

        // Socio non disponibile all'emissione: salvati solo i dati della quota
        $ricevuta = Ricevuta::allTenants()->create([
            'associazione_id' => $this->associazione->id,
            'quota_id' => $quota->id,
            'numero' => 1,
            'anno' => 2026,
            'importo' => 30,
            'emessa_at' => now(),
            'tipo_quota' => 'Quota Ordinaria',
            'metodo_pagamento' => 'bonifico',
            'data_pagamento' => '2026-03-10',
            'riferimento_transazione' => 'TRN-001',
        ]);
        $this->assertTrue($ricevuta->haIstantanea());

        $quota->update(['tipo' => 'Quota Sostenitore', 'metodo_pagamento' => 'contanti', 'gateway_transaction_id' => null]);

        $html = $this->htmlRicevuta($ricevuta);

        $this->assertStringContainsString('Quota Ordinaria', $html);
        $this->assertStringContainsString('Bonifico', $html);
        $this->assertStringContainsString('TRN-001', $html);
        $this->assertStringNotContainsString('Quota Sostenitore', $html);
        // L'intestatario ricade sul socio corrente
        $this->assertStringContainsString('Maria Rossi', $html);
    }

    public function test_l_istantanea_usa_la_quota_riletta_anche_se_riassegnata_dopo_il_caricamento(): void
    {
        $quota = $this->quotaPagata($this->socioDiProva());
        $altro = $this->creaSocio(['nome' => 'Giulio', 'cognome' => 'Verdi', 'numero_socio' => '77']);

        // Un amministratore riassegna la quota dopo che è stata caricata
        // (es. dal webhook) ma prima dell'emissione della ricevuta
        DB::table('quote')->where('id', $quota->id)->update(['socio_id' => $altro->id, 'tipo' => 'Quota Sostenitore']);

        $ricevuta = app(RicevutaService::class)->emettiPerQuota($quota);

        $this->assertNotNull($ricevuta);
        $ricevuta->refresh();
        $this->assertSame('Giulio', $ricevuta->intestatario_nome);
        $this->assertSame('Verdi', $ricevuta->intestatario_cognome);
        $this->assertSame('77', $ricevuta->numero_socio);
        $this->assertSame('Quota Sostenitore', $ricevuta->tipo_quota);
    }
}
