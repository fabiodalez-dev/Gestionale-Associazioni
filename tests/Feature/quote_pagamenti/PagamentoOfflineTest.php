<?php

namespace Tests\Feature\quote_pagamenti;

use App\Models\Quota;
use App\Notifications\Quote\PagamentoQuotaNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PagamentoOfflineTest extends TestCase
{
    use ConfiguraTenantQuote;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configuraTenant();
        Notification::fake();
    }

    public function test_registra_pagamento_offline(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio);

        $response = $this->actingAs($this->admin)->post(route('quote.pagamento', $quota), [
            'payment_method' => 'bonifico',
            'payment_date' => '2026-06-01',
        ]);

        $response->assertRedirect(route('quote.index'));
        $response->assertSessionHas('success', 'Pagamento registrato con successo.');

        $quota->refresh();
        $this->assertSame('2026-06-01', $quota->data_pagamento?->toDateString());
        $this->assertSame('bonifico', $quota->metodo_pagamento);
        // Parità legacy: la colonna stato NON viene toccata dal pagamento manuale
        $this->assertSame(Quota::STATO_DA_PAGARE, $quota->getRawOriginal('stato'));
        // ...ma lo stato calcolato è Pagata (data_pagamento è la fonte di verità)
        $this->assertSame(Quota::STATO_PAGATA, $quota->statoCalcolato());

        $this->assertDatabaseHas('storico_attivita_socio', [
            'socio_id' => $socio->id,
            'tipo_attivita' => 'Pagamento Quota',
        ]);

        Notification::assertSentTo($socio, PagamentoQuotaNotification::class);
    }

    public function test_pagamento_rifiutato_se_quota_gia_pagata(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio, [
            'data_pagamento' => '2026-01-15',
            'metodo_pagamento' => 'contanti',
        ]);

        $response = $this->actingAs($this->admin)->post(route('quote.pagamento', $quota), [
            'payment_method' => 'bonifico',
            'payment_date' => '2026-06-01',
        ]);

        $response->assertSessionHas('error', 'Questa quota risulta già pagata.');
        $this->assertSame('2026-01-15', $quota->fresh()->data_pagamento?->toDateString());
    }

    public function test_validazione_metodo_e_data(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio);

        $this->actingAs($this->admin)
            ->post(route('quote.pagamento', $quota), ['payment_method' => 'assegno'])
            ->assertSessionHasErrors('payment_method');

        $this->actingAs($this->admin)
            ->post(route('quote.pagamento', $quota), ['payment_method' => 'contanti'])
            ->assertSessionHasErrors('payment_date');

        $this->assertNull($quota->fresh()->data_pagamento);
    }

    public function test_metodo_online_genera_link_invece_di_registrare(): void
    {
        $this->configuraStripe();
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio);

        $response = $this->actingAs($this->admin)->post(route('quote.pagamento', $quota), [
            'payment_method' => 'stripe',
        ]);

        $response->assertRedirect(route('quote.index'));
        $response->assertSessionHas('payment_link');

        $quota->refresh();
        // Il pagamento NON viene registrato: viene solo generato il token
        $this->assertNull($quota->data_pagamento);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $quota->payment_token);
    }

    public function test_metodo_online_senza_gateway_configurato_da_errore(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio);

        $response = $this->actingAs($this->admin)->post(route('quote.pagamento', $quota), [
            'payment_method' => 'paypal',
        ]);

        $response->assertSessionHas('error');
        $this->assertNull($quota->fresh()->payment_token);
    }
}
