<!DOCTYPE html>
<html lang="it">
<body style="font-family: Arial, sans-serif; color: #111827; background: #F3F4F6; padding: 24px;">
    <div style="max-width: 560px; margin: 0 auto; background: #FFFFFF; border-radius: 12px; padding: 32px;">
        <h1 style="font-size: 20px; color: #0D9488; margin-top: 0;">Hai provato a iscrivere un'associazione?</h1>
        <p>Qualcuno ha appena compilato il modulo di iscrizione a {{ config('app.name', 'Gestione Associazioni') }} indicando questo indirizzo come email dell'amministratore.</p>
        <p>L'indirizzo è già collegato a un account della piattaforma o a una richiesta di iscrizione in corso, quindi non è stata creata una nuova richiesta.</p>
        <p>Se sei stato tu, accedi con il tuo account oppure, se non ricordi la password, recuperala:</p>
        <p style="text-align: center; margin: 28px 0;">
            <a href="{{ route('login') }}" style="background: #0D9488; color: #FFFFFF; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: bold;">Accedi</a>
        </p>
        <p style="font-size: 13px; color: #6B7280;">Password dimenticata? <a href="{{ route('password.request') }}" style="color: #0D9488;">Recuperala da qui</a>. Se la richiesta di iscrizione è ancora in attesa, riceverai l'esito via email.</p>
        <p style="font-size: 13px; color: #6B7280;">Se non sei stato tu, ignora questa email: il tuo account non è stato modificato.</p>
    </div>
</body>
</html>
