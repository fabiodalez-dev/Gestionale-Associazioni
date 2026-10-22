<!DOCTYPE html>
<html lang="it">
<body style="font-family: Arial, sans-serif; color: #111827; background: #F3F4F6; padding: 24px;">
    <div style="max-width: 560px; margin: 0 auto; background: #FFFFFF; border-radius: 12px; padding: 32px;">
        <h1 style="font-size: 20px; color: #0D9488; margin-top: 0;">Nuova richiesta di iscrizione</h1>
        <p><strong>{{ $richiesta->nome }}</strong>{{ $richiesta->tipo ? ' ('.$richiesta->tipo.')' : '' }}{{ $richiesta->citta ? ', '.$richiesta->citta : '' }} chiede di iscriversi alla piattaforma.</p>
        <p>Amministratore indicato: {{ trim($richiesta->admin_nome.' '.$richiesta->admin_cognome) }} — {{ $richiesta->admin_email }}</p>
        @if ($richiesta->messaggio)
            <p style="background: #F7F8FA; border-radius: 8px; padding: 12px 14px;">{{ $richiesta->messaggio }}</p>
        @endif
        <p style="text-align: center; margin: 28px 0;">
            <a href="{{ route('richieste-registrazione.index') }}" style="background: #0D9488; color: #FFFFFF; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: bold;">Esamina la richiesta</a>
        </p>
    </div>
</body>
</html>
