<?php

namespace Tests\Feature\Membri;

use App\Exports\SociExport;
use App\Models\Associazione;
use App\Models\Socio;
use App\Models\Utente;
use App\Services\Soci\SociFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Honeypot\ProtectAgainstSpam;
use Tests\TestCase;

/**
 * f.22 (piano "Gestione 2") — Soci minorenni: tutore obbligatorio
 * (validazione dinamica sull'età) nel form admin e nel form pubblico
 * di iscrizione, badge "Minorenne" nelle liste, colonne tutore
 * nell'export.
 */
class SociMinorenniTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create([
            'slug' => 'minori',
            'frontend_pubblico_attivo' => true,
            'iscrizioni_socio_aperte' => true,
            'modalita_iscrizione' => 'preiscrizione',
        ]);
        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->withoutMiddleware(ProtectAgainstSpam::class);
    }

    /** @return array<string, mixed> */
    private function payloadAdmin(array $override = []): array
    {
        return array_merge([
            'nome' => 'Luca',
            'cognome' => 'Giovane',
            'email' => 'luca.giovane@example.com',
            'data_iscrizione' => now()->toDateString(),
            'stato' => Socio::STATO_ATTIVO,
            'telefono' => '3331234567',
            'privacy_consenso' => '1',
            'genera_tessera' => '0',
        ], $override);
    }

    private function dataNascitaMinorenne(): string
    {
        return now()->subYears(15)->toDateString();
    }

    // ------------------------------------------------------------------
    // Form admin
    // ------------------------------------------------------------------

    public function test_admin_minorenne_senza_tutore_viene_bloccato(): void
    {
        $this->actingAs($this->admin)
            ->from('/soci/crea')
            ->post('/soci', $this->payloadAdmin([
                'data_nascita' => $this->dataNascitaMinorenne(),
            ]))
            ->assertSessionHasErrors(['tutore_nome', 'tutore_telefono', 'tutore_email']);

        $this->assertDatabaseMissing('soci', ['email' => 'luca.giovane@example.com']);
    }

    public function test_admin_minorenne_con_tutore_viene_creato(): void
    {
        $this->actingAs($this->admin)
            ->post('/soci', $this->payloadAdmin([
                'data_nascita' => $this->dataNascitaMinorenne(),
                'tutore_nome' => 'Maria Giovane',
                'tutore_telefono' => '3399876543',
                'tutore_email' => 'maria.giovane@example.com',
            ]))
            ->assertSessionDoesntHaveErrors();

        $socio = Socio::withoutGlobalScopes()->where('email', 'luca.giovane@example.com')->firstOrFail();
        $this->assertTrue($socio->isMinorenne());
        $this->assertSame('Maria Giovane', $socio->tutore_nome);
        $this->assertSame('3399876543', $socio->tutore_telefono);
        $this->assertSame('maria.giovane@example.com', $socio->tutore_email);
    }

    public function test_admin_maggiorenne_non_richiede_il_tutore(): void
    {
        $this->actingAs($this->admin)
            ->post('/soci', $this->payloadAdmin([
                'data_nascita' => now()->subYears(30)->toDateString(),
            ]))
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('soci', ['email' => 'luca.giovane@example.com']);
    }

    public function test_anche_l_update_admin_applica_la_validazione_dinamica(): void
    {
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'data_nascita' => now()->subYears(30)->toDateString(),
        ]);

        $this->actingAs($this->admin)
            ->from(route('soci.edit', $socio))
            ->put("/soci/{$socio->id}", $this->payloadAdmin([
                'email' => $socio->email,
                'numero_socio' => $socio->numero_socio,
                'data_nascita' => $this->dataNascitaMinorenne(),
            ]))
            ->assertSessionHasErrors(['tutore_nome', 'tutore_telefono', 'tutore_email']);
    }

    // ------------------------------------------------------------------
    // Form pubblico /a/{slug}/iscriviti
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function payloadPubblico(array $override = []): array
    {
        return array_merge([
            'nome' => 'Sara',
            'cognome' => 'Junior',
            'email' => 'sara.junior@example.com',
            'telefono' => '+39 333 0000001',
            'data_nascita' => $this->dataNascitaMinorenne(),
            'privacy' => '1',
        ], $override);
    }

    public function test_pubblico_minorenne_senza_tutore_viene_bloccato(): void
    {
        $this->post('/a/minori/iscriviti', $this->payloadPubblico())
            ->assertSessionHasErrors(['tutore_nome', 'tutore_telefono', 'tutore_email']);

        $this->assertDatabaseCount('richieste_iscrizione', 0);
    }

    public function test_pubblico_minorenne_con_tutore_crea_il_preiscritto_con_tutore(): void
    {
        $this->post('/a/minori/iscriviti', $this->payloadPubblico([
            'tutore_nome' => 'Paolo Junior',
            'tutore_telefono' => '+39 339 9876543',
            'tutore_email' => 'paolo.junior@example.com',
        ]))->assertSessionHas('success');

        $socio = Socio::withoutGlobalScopes()->where('email', 'sara.junior@example.com')->firstOrFail();
        $this->assertTrue($socio->isMinorenne());
        $this->assertSame('Paolo Junior', $socio->tutore_nome);
        $this->assertSame('paolo.junior@example.com', $socio->tutore_email);
    }

    public function test_pubblico_maggiorenne_non_richiede_il_tutore(): void
    {
        $this->post('/a/minori/iscriviti', $this->payloadPubblico([
            'data_nascita' => '1990-05-05',
        ]))->assertSessionHas('success');

        $this->assertDatabaseHas('soci', ['email' => 'sara.junior@example.com']);
    }

    public function test_il_form_pubblico_mostra_il_blocco_tutore(): void
    {
        $this->get('/a/minori/iscriviti')
            ->assertOk()
            ->assertSee('blocco-tutore')
            ->assertSee('name="tutore_nome"', false);
    }

    // ------------------------------------------------------------------
    // Badge nelle liste
    // ------------------------------------------------------------------

    public function test_badge_minorenne_nella_lista_e_nella_scheda(): void
    {
        $minorenne = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'cognome' => 'Giovanissimo',
            'data_nascita' => $this->dataNascitaMinorenne(),
            'tutore_nome' => 'Tutore Test',
        ]);

        $json = $this->actingAs($this->admin)
            ->get('/soci?tessera_stato=all&ajax=1')
            ->assertOk()
            ->json();

        $this->assertStringContainsString('Minorenne', $json['html']);

        $this->actingAs($this->admin)
            ->get(route('soci.show', $minorenne))
            ->assertOk()
            ->assertSee('Minorenne')
            ->assertSee('Tutore Test');
    }

    public function test_nessun_badge_per_i_maggiorenni(): void
    {
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'data_nascita' => '1980-01-01',
        ]);

        $json = $this->actingAs($this->admin)
            ->get('/soci?tessera_stato=all&ajax=1')
            ->assertOk()
            ->json();

        $this->assertStringNotContainsString('Minorenne', $json['html']);
    }

    // ------------------------------------------------------------------
    // Export
    // ------------------------------------------------------------------

    public function test_export_contiene_le_colonne_tutore(): void
    {
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'data_nascita' => $this->dataNascitaMinorenne(),
            'tutore_nome' => 'Tutore Export',
            'tutore_telefono' => '3331112222',
            'tutore_email' => 'tutore.export@example.com',
        ]);
        $socio->load(['tags', 'ultimaTessera', 'nucleo']);

        $export = new SociExport(new SociFilter([], $this->associazione));

        $headings = $export->headings();
        $this->assertContains('Minorenne', $headings);
        $this->assertContains('Tutore', $headings);
        $this->assertContains('Telefono Tutore', $headings);
        $this->assertContains('Email Tutore', $headings);

        $riga = $export->map($socio);
        $this->assertContains('Sì', $riga);
        $this->assertContains('Tutore Export', $riga);
        $this->assertContains('3331112222', $riga);
        $this->assertContains('tutore.export@example.com', $riga);
    }
}
