<!DOCTYPE html>
<html lang="it">
<body style="font-family: Arial, sans-serif; color: #111827; background: #F3F4F6; padding: 24px;">
    <div style="max-width: 560px; margin: 0 auto; background: #FFFFFF; border-radius: 12px; padding: 32px;">
        <h1 style="font-size: 20px; color: #0D9488; margin-top: 0;">Sei stato invitato come amministratore</h1>
        <p>Ciao {{ $utente->nome_completo }},</p>
        <p>
            è stato creato per te un account amministratore
            @if ($utente->associazione)
                per l'associazione <strong>{{ $utente->associazione->nome }}</strong>
            @endif
            su {{ config('app.name', 'Gestione Associazioni') }}.
        </p>
        <p>Per attivare l'accesso imposta la tua password dal link qui sotto (valido 7 giorni):</p>
        <p style="text-align: center; margin: 28px 0;">
            <a href="{{ $urlInvito }}" style="background: #0D9488; color: #FFFFFF; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: bold;">
                Imposta la password
            </a>
        </p>
        <p style="font-size: 13px; color: #6B7280;">
            Se il pulsante non funziona, copia e incolla questo indirizzo nel browser:<br>
            {{ $urlInvito }}
        </p>
        <p style="font-size: 13px; color: #6B7280;">Se non ti aspettavi questo invito puoi ignorare questa email.</p>
    </div>
</body>
</html>
