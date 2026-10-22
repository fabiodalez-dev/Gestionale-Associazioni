<?php

namespace Tests\Feature\Iscrizioni;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\RichiestaIscrizione;
use App\Models\Socio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cancellazione GDPR di un account con una richiesta di iscrizione ancora
 * in attesa: il socio preiscritto (dati in chiaro) viene eliminato, la
 * richiesta chiusa senza dati, l'email torna libera.
 */
class CancellazioneAccountPreiscrittoTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonimizza_elimina_il_preiscritto_e_chiude_la_richiesta_in_attesa(): void
    {
        $associazione = Associazione::factory()->create();
        $account = Account::factory()->verificato()->create(['associazione_id' => $associazione->id, 'email' => 'giulia@prova.test']);
        $richiesta = RichiestaIscrizione::factory()->inAttesa()->conSocio()->create([
            'associazione_id' => $associazione->id,
            'account_id' => $account->id,
            'dati_form' => ['telefono' => '+39 333 0000000', 'codice_fiscale' => 'RSSMRA80A01H501U'],
        ]);
        $socioId = $richiesta->socio_id;
        $this->assertSame(Socio::STATO_IN_ATTESA, Socio::withoutGlobalScopes()->find($socioId)->stato);

        $account->anonimizza();

        $this->assertNull(Socio::withoutGlobalScopes()->withTrashed()->find($socioId), 'Il preiscritto va eliminato definitivamente');
        $richiesta->refresh();
        $this->assertSame(RichiestaIscrizione::STATO_RIFIUTATA, $richiesta->stato);
        $this->assertNull($richiesta->socio_id);
        $this->assertSame([], $richiesta->dati_form);
        $this->assertStringContainsString('cancellato', (string) $richiesta->motivo_rifiuto);
        // Nessun socio con quell'email: una nuova iscrizione non viene bloccata
        $this->assertSame(0, Socio::withoutGlobalScopes()->withTrashed()->where('email', 'giulia@prova.test')->count());
    }

    public function test_anonimizza_non_tocca_un_socio_gia_attivo_collegato_all_account(): void
    {
        $associazione = Associazione::factory()->create();
        $socio = Socio::factory()->create(['associazione_id' => $associazione->id, 'stato' => Socio::STATO_ATTIVO]);
        $account = Account::factory()->verificato()->collegatoASocio($socio)->create(['associazione_id' => $associazione->id]);
        RichiestaIscrizione::factory()->approvata()->create(['associazione_id' => $associazione->id, 'account_id' => $account->id, 'socio_id' => $socio->id]);

        $account->anonimizza();

        $this->assertNotNull(Socio::withoutGlobalScopes()->find($socio->id), 'Il socio attivo è un dato dell\'associazione, resta');
    }
}
