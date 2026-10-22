<!DOCTYPE html>
<html lang="it">
<body style="font-family: Arial, sans-serif; color: #111827; background: #F3F4F6; padding: 24px;">
    <div style="max-width: 560px; margin: 0 auto; background: #FFFFFF; border-radius: 12px; padding: 32px;">
        <p>Ciao {{ $richiesta->admin_nome }},</p>
        @if ($richiesta->stato === \App\Models\RichiestaRegistrazione::STATO_APPROVATA)
            <h1 style="font-size: 20px; color: #0D9488;">{{ $richiesta->nome }} è attiva</h1>
            <p>L'iscrizione di <strong>{{ $richiesta->nome }}</strong> a {{ config('app.name', 'Gestione Associazioni') }} è stata attivata. Puoi accedere con l'email <strong>{{ $richiesta->admin_email }}</strong> e la password scelta durante la registrazione.</p>
            <p style="text-align: center; margin: 28px 0;">
                <a href="{{ route('login') }}" style="background: #0D9488; color: #FFFFFF; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: bold;">Accedi</a>
            </p>
            <p>Al primo accesso troverai una lista di passi per configurare l'associazione: logo, quote, modulo di iscrizione e importazione dei soci da Excel.</p>
        @else
            <h1 style="font-size: 20px; color: #C9372C;">Richiesta non accolta</h1>
            <p>La richiesta di iscrizione di <strong>{{ $richiesta->nome }}</strong> non è stata accolta.</p>
            @if ($richiesta->motivo_rifiuto)
                <p style="background: #F7F8FA; border-radius: 8px; padding: 12px 14px;">{{ $richiesta->motivo_rifiuto }}</p>
            @endif
            <p>Per chiarimenti puoi rispondere a questa email.</p>
        @endif
    </div>
</body>
</html>
