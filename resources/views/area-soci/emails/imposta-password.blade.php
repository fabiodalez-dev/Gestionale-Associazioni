<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Imposta la tua password</title>
</head>
<body style="font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; background: #F3F4F6; margin: 0; padding: 24px;">
    <div style="max-width: 520px; margin: 0 auto; background: #ffffff; border-radius: 12px; padding: 32px; border: 1px solid #E5E7EB;">
        <h1 style="font-size: 18px; color: #111827; margin: 0 0 16px;">Ciao {{ $socio->nome }},</h1>
        <p style="font-size: 14px; color: #4B5563; line-height: 1.6;">
            hai richiesto di impostare (o reimpostare) la password per accedere all'area soci
            @if ($socio->associazione !== null)
                di <strong>{{ $socio->associazione->nome }}</strong>@endif.
            Clicca sul pulsante qui sotto per scegliere la tua password:
        </p>
        <p style="text-align: center; margin: 28px 0;">
            <a href="{{ $url }}" style="background: #0D9488; color: #ffffff; text-decoration: none; padding: 12px 28px; border-radius: 8px; font-weight: bold; font-size: 14px; display: inline-block;">
                Imposta la password
            </a>
        </p>
        <p style="font-size: 13px; color: #6B7280; line-height: 1.6;">
            Il link &egrave; valido per <strong>1 ora</strong> e pu&ograve; essere usato una sola volta.
            Se non hai richiesto tu questa operazione, ignora questa email: la tua password attuale rimarr&agrave; invariata.
        </p>
        <p style="font-size: 12px; color: #9CA3AF; line-height: 1.5; border-top: 1px solid #E5E7EB; padding-top: 16px; margin-top: 24px;">
            Se il pulsante non funziona, copia e incolla questo indirizzo nel browser:<br>
            <span style="word-break: break-all;">{{ $url }}</span>
        </p>
    </div>
</body>
</html>
