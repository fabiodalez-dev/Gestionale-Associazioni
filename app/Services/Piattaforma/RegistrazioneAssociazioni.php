<?php

namespace App\Services\Piattaforma;

use App\Mail\Piattaforma\EsitoRegistrazioneMail;
use App\Mail\Piattaforma\NuovaRichiestaRegistrazioneMail;
use App\Models\Associazione;
use App\Models\RichiestaRegistrazione;
use App\Models\Sede;
use App\Models\Utente;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Iscrizione delle associazioni alla piattaforma: registrazione della
 * richiesta, approvazione (crea associazione, sede principale e primo
 * amministratore), rifiuto, avvisi email.
 */
class RegistrazioneAssociazioni
{
    /**
     * Salva la richiesta e, in modalità automatica, la approva subito.
     *
     * @param  array<string, mixed>  $dati  dati validati del modulo pubblico
     */
    public function registra(array $dati, ?string $ip): RichiestaRegistrazione
    {
        $richiesta = RichiestaRegistrazione::create([
            'stato' => RichiestaRegistrazione::STATO_IN_ATTESA,
            'nome' => $dati['nome'],
            'email' => Str::lower($dati['email']),
            'tipo' => $dati['tipo'] ?? null,
            'codice_fiscale' => isset($dati['codice_fiscale']) ? Str::upper($dati['codice_fiscale']) : null,
            'partita_iva' => $dati['partita_iva'] ?? null,
            'telefono' => $dati['telefono'] ?? null,
            'citta' => $dati['citta'] ?? null,
            'provincia' => isset($dati['provincia']) ? Str::upper($dati['provincia']) : null,
            'numero_soci_stimato' => $dati['numero_soci_stimato'] ?? null,
            'messaggio' => $dati['messaggio'] ?? null,
            'admin_nome' => $dati['admin_nome'],
            'admin_cognome' => $dati['admin_cognome'] ?? null,
            'admin_email' => Str::lower($dati['admin_email']),
            'admin_password_hash' => bcrypt($dati['admin_password']),
            'ip' => $ip,
            'privacy_accettata_at' => now(),
        ]);

        if (RichiestaRegistrazione::modalita() === 'automatica') {
            $this->approva($richiesta, null);
        } else {
            $this->avvisaSuperAdmin($richiesta);
        }

        return $richiesta->refresh();
    }

    /**
     * Crea associazione, sede principale e amministratore dalla richiesta.
     */
    public function approva(RichiestaRegistrazione $richiesta, ?Utente $superAdmin): Associazione
    {
        abort_unless($richiesta->isInAttesa(), 409, 'La richiesta è già stata gestita.');

        $associazione = DB::transaction(function () use ($richiesta, $superAdmin): Associazione {
            $associazione = Associazione::create([
                'nome' => $richiesta->nome,
                'email' => $richiesta->email,
                'codice_fiscale' => $richiesta->codice_fiscale,
                'partita_iva' => $richiesta->partita_iva,
                'telefono' => $richiesta->telefono,
                'citta' => $richiesta->citta,
                'provincia' => $richiesta->provincia,
                'tipo_scadenza_default' => 'solare',
                'giorni_notifica_scadenza' => 30,
                'attiva' => true,
            ]);

            Sede::create([
                'associazione_id' => $associazione->id,
                'nome' => 'Sede Principale',
                'citta' => $richiesta->citta,
            ]);

            $admin = new Utente([
                'associazione_id' => $associazione->id,
                'nome' => $richiesta->admin_nome,
                'cognome' => $richiesta->admin_cognome ?? '',
                'email' => $richiesta->admin_email,
                'ruolo' => Utente::RUOLO_ADMIN_ASSOCIAZIONE,
                'attivo' => true,
            ]);
            // Hash già calcolato all'invio del modulo: la password in chiaro non viene conservata
            $admin->setRawAttributes(array_merge($admin->getAttributes(), ['password_hash' => $richiesta->admin_password_hash]));
            $admin->save();
            $admin->assignRole(Role::findOrCreate(Utente::RUOLO_ADMIN_ASSOCIAZIONE, 'web'));

            $richiesta->update([
                'stato' => RichiestaRegistrazione::STATO_APPROVATA,
                'associazione_id' => $associazione->id,
                'gestita_da' => $superAdmin?->id,
                'gestita_at' => now(),
            ]);

            return $associazione;
        });

        $this->invia($richiesta->admin_email, new EsitoRegistrazioneMail($richiesta->refresh()));

        return $associazione;
    }

    public function rifiuta(RichiestaRegistrazione $richiesta, Utente $superAdmin, ?string $motivo): void
    {
        abort_unless($richiesta->isInAttesa(), 409, 'La richiesta è già stata gestita.');

        $richiesta->update([
            'stato' => RichiestaRegistrazione::STATO_RIFIUTATA,
            'motivo_rifiuto' => $motivo,
            'gestita_da' => $superAdmin->id,
            'gestita_at' => now(),
            // La password non serve più: niente hash conservato senza motivo
            'admin_password_hash' => '-',
        ]);

        $this->invia($richiesta->admin_email, new EsitoRegistrazioneMail($richiesta->refresh()));
    }

    private function avvisaSuperAdmin(RichiestaRegistrazione $richiesta): void
    {
        $configurati = array_filter(array_map('trim', explode(',', (string) config('piattaforma.registrazione_notifica_email'))));
        $destinatari = $configurati !== []
            ? $configurati
            : Utente::query()->withoutGlobalScopes()->where('ruolo', Utente::RUOLO_SUPER_ADMIN)->where('attivo', true)->pluck('email')->all();

        foreach ($destinatari as $email) {
            $this->invia($email, new NuovaRichiestaRegistrazioneMail($richiesta));
        }
    }

    /**
     * L'email è un di più: un problema di invio non blocca l'iscrizione.
     */
    private function invia(string $destinatario, Mailable $mail): void
    {
        try {
            Mail::to($destinatario)->send($mail);
        } catch (\Throwable $e) {
            Log::warning('Email registrazione associazione non inviata: '.$e->getMessage());
        }
    }
}
