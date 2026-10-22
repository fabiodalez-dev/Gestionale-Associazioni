<?php

namespace Tests\Feature\Formello;

use App\Forms\AmministratoreForm;
use App\Forms\ImpostaPasswordForm;
use App\Forms\PreiscrizioneForm;
use App\Forms\RegistrazioneAccountForm;
use App\Models\Account;
use App\Models\Associazione;
use App\Models\Socio;
use App\Models\Utente;
use App\Support\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class FormelloFormsTest extends TestCase
{
    use RefreshDatabase;

    private function html(object $form): string
    {
        return view('formello.campi', ['form' => $form])->render();
    }

    public function test_il_widget_password_non_ripopola_mai_il_valore(): void
    {
        $account = Account::factory()->create(['password' => 'Lampione-Viola-Treno-42']);
        $this->withSession(['_old_input' => ['password' => 'segreto-inviato']]);
        request()->setLaravelSession(app('session.store'));

        $html = $this->html(new ImpostaPasswordForm($account, ['password_attuale' => 'password_corrente']));

        $this->assertStringNotContainsString('segreto-inviato', $html);
        $this->assertStringNotContainsString((string) $account->getAttributes()['password'], $html);
        $this->assertMatchesRegularExpression('/name="password" value=""/', $html);
        $this->assertStringContainsString('name="password_corrente"', $html);
        $this->assertStringContainsString('autocomplete="current-password"', $html);
    }

    public function test_il_widget_password_espone_le_soglie_della_policy(): void
    {
        $html = $this->html(new ImpostaPasswordForm(Utente::class, ['dati_utente' => ['mario@example.com', 'Mario']]));

        $this->assertStringContainsString('data-password-strength', $html);
        $this->assertStringContainsString('data-min-length="'.PasswordPolicy::MIN_LENGTH.'"', $html);
        $this->assertStringContainsString('data-min-score="'.config('zxcvbn.min_score').'"', $html);
        $this->assertStringContainsString('&quot;mario@example.com&quot;', $html);
        $this->assertStringContainsString('maxlength="'.PasswordPolicy::MAX_LENGTH.'"', $html);
        // La conferma non ha l'indicatore
        $this->assertSame(1, substr_count($html, 'data-password-meter-for='));
    }

    public function test_campi_obbligatori_e_facoltativi_della_preiscrizione(): void
    {
        $associazione = Associazione::factory()->create();
        $form = PreiscrizioneForm::nuovo($associazione, null, ['telefono' => true, 'cap' => false], collect(), collect());
        $html = $this->html($form);

        $this->assertMatchesRegularExpression('/name="telefono"[^>]*\srequired[\s>]/s', $html);
        $this->assertDoesNotMatchRegularExpression('/name="cap"[^>]*\srequired[\s>]/s', $html);
        // Nessun attributo required="" spurio sui campi facoltativi
        $this->assertStringNotContainsString('required=""', $html);
        $this->assertStringNotContainsString('name="tipo_socio_id"', $html);
    }

    public function test_preiscrizione_di_rinnovo_precompila_i_dati(): void
    {
        $associazione = Associazione::factory()->create();
        $socio = Socio::factory()->create([
            'associazione_id' => $associazione->id,
            'nome' => 'Giulia',
            'data_nascita' => '1990-05-17',
        ]);

        $html = $this->html(PreiscrizioneForm::nuovo($associazione, $socio, [], collect(), collect()));

        $this->assertStringContainsString('value="Giulia"', $html);
        $this->assertStringContainsString('value="1990-05-17"', $html);
        $this->assertMatchesRegularExpression('/name="privacy_consenso" value="1"\s+checked/', $html);
    }

    public function test_errori_di_validazione_sotto_il_campo(): void
    {
        $errori = (new ViewErrorBag)->put('default', new MessageBag(['email' => ['Email già registrata.']]));
        $this->withSession(['errors' => $errori]);
        request()->setLaravelSession(app('session.store'));

        $associazione = Associazione::factory()->create();
        $html = $this->html(new RegistrazioneAccountForm(Account::class, ['associazione' => $associazione]));

        $this->assertMatchesRegularExpression('/name="email"[^>]*class="form-control\s+is-invalid/s', $html);
        $this->assertStringContainsString('<div class="invalid-feedback d-block">Email già registrata.</div>', $html);
        $this->assertStringContainsString(route('privacy-policy.show', $associazione->id), $html);
    }

    public function test_form_amministratore_in_creazione_e_modifica(): void
    {
        $associazione = Associazione::factory()->create(['nome' => 'ASD Prova']);
        $contesto = ['ruoli' => Utente::RUOLI, 'associazioni' => [$associazione->id => $associazione->nome]];

        $nuovo = $this->html(new AmministratoreForm(Utente::class, $contesto));
        $this->assertStringContainsString('name="invia_invito"', $nuovo);
        // Senza campo nascosto "0": la regola required_without:invia_invito controlla la presenza
        $this->assertStringNotContainsString('name="invia_invito" value="0"', $nuovo);
        $this->assertStringNotContainsString('name="attivo"', $nuovo);

        $utente = Utente::factory()->operatore()->create(['associazione_id' => $associazione->id, 'attivo' => true]);
        $modifica = $this->html(new AmministratoreForm($utente, $contesto));
        $this->assertStringContainsString('Password (vuota = invariata)', $modifica);
        $this->assertMatchesRegularExpression('/name="attivo" value="1"\s+checked/', $modifica);
        $this->assertMatchesRegularExpression('/<option value="'.$associazione->id.'"\s+selected/', $modifica);
    }

    public function test_la_rotta_compute_di_formello_non_e_esposta(): void
    {
        $this->assertFalse(Route::has('formello.compute'));
        $this->post('/formello/compute', ['form_class' => Utente::class, 'changed_field' => 'x', 'form_data' => ['a' => 1]])
            ->assertNotFound();
    }
}
