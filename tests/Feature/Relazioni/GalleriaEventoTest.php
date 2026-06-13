<?php

namespace Tests\Feature\Relazioni;

use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * f.24 — Galleria foto eventi (medialibrary, collection 'galleria'): tab admin
 * di upload/eliminazione, toggle visibilità e griglia sulla pagina pubblica
 * dell'evento quando galleria_pubblica è attiva.
 */
class GalleriaEventoTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    private Evento $evento;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->associazione = Associazione::factory()->create([
            'frontend_pubblico_attivo' => true,
        ]);
        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $this->evento = Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'stato_pubblicazione' => Evento::STATO_PUBBLICATO,
        ]);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('tenant.pubblico');

        parent::tearDown();
    }

    public function test_tab_galleria_accessibile_dall_admin(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/eventi/'.$this->evento->id.'/galleria');

        $response->assertOk();
        $response->assertViewIs('eventi.galleria.index');
    }

    public function test_upload_foto_nella_galleria(): void
    {
        $this->actingAs($this->admin)
            ->post('/eventi/'.$this->evento->id.'/galleria', [
                'foto' => [
                    UploadedFile::fake()->image('foto1.jpg'),
                    UploadedFile::fake()->image('foto2.png'),
                ],
            ])
            ->assertRedirect('/eventi/'.$this->evento->id.'/galleria')
            ->assertSessionHas('success');

        $this->assertCount(2, $this->evento->fresh()->getMedia(Evento::COLLEZIONE_GALLERIA));
    }

    public function test_upload_rifiuta_file_non_immagine(): void
    {
        $this->actingAs($this->admin)
            ->post('/eventi/'.$this->evento->id.'/galleria', [
                'foto' => [UploadedFile::fake()->create('documento.pdf', 100, 'application/pdf')],
            ])
            ->assertSessionHasErrors('foto.0');

        $this->assertCount(0, $this->evento->fresh()->getMedia(Evento::COLLEZIONE_GALLERIA));
    }

    public function test_elimina_una_foto(): void
    {
        $this->evento->addMedia(UploadedFile::fake()->image('da-eliminare.jpg'))
            ->toMediaCollection(Evento::COLLEZIONE_GALLERIA);

        $media = $this->evento->fresh()->getFirstMedia(Evento::COLLEZIONE_GALLERIA);
        $this->assertNotNull($media);

        $this->actingAs($this->admin)
            ->delete('/eventi/'.$this->evento->id.'/galleria/'.$media->id)
            ->assertRedirect('/eventi/'.$this->evento->id.'/galleria');

        $this->assertCount(0, $this->evento->fresh()->getMedia(Evento::COLLEZIONE_GALLERIA));
    }

    public function test_toggle_visibilita_pubblica(): void
    {
        $this->assertFalse($this->evento->galleria_pubblica);

        $this->actingAs($this->admin)
            ->post('/eventi/'.$this->evento->id.'/galleria/visibilita')
            ->assertRedirect();

        $this->assertTrue($this->evento->fresh()->galleria_pubblica);
    }

    public function test_galleria_pubblica_visibile_sulla_pagina_evento_solo_se_attiva(): void
    {
        $this->evento->addMedia(UploadedFile::fake()->image('pubblica.jpg'))
            ->toMediaCollection(Evento::COLLEZIONE_GALLERIA);

        // Galleria privata → la sezione non appare
        $this->get('/a/'.$this->associazione->slug.'/eventi/'.$this->evento->id)
            ->assertOk()
            ->assertDontSee('Galleria foto');

        // Attivata → la sezione appare
        $this->evento->update(['galleria_pubblica' => true]);

        $this->get('/a/'.$this->associazione->slug.'/eventi/'.$this->evento->id)
            ->assertOk()
            ->assertSee('Galleria foto');
    }

    public function test_non_si_puo_caricare_su_evento_di_altro_tenant(): void
    {
        $altra = Associazione::factory()->create();
        $eventoAltrui = Evento::factory()->create([
            'associazione_id' => $altra->id,
        ]);

        $this->actingAs($this->admin)
            ->post('/eventi/'.$eventoAltrui->id.'/galleria', [
                'foto' => [UploadedFile::fake()->image('x.jpg')],
            ])
            ->assertNotFound();
    }

    public function test_eliminare_media_di_altro_evento_da_404(): void
    {
        $altroEvento = Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $altroEvento->addMedia(UploadedFile::fake()->image('altra.jpg'))
            ->toMediaCollection(Evento::COLLEZIONE_GALLERIA);
        $mediaAltrui = $altroEvento->getFirstMedia(Evento::COLLEZIONE_GALLERIA);

        // media appartiene ad altroEvento, non a $this->evento → 404
        $this->actingAs($this->admin)
            ->delete('/eventi/'.$this->evento->id.'/galleria/'.$mediaAltrui->id)
            ->assertNotFound();
    }
}
