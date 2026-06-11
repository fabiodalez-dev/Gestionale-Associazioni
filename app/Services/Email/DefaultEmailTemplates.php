<?php

namespace App\Services\Email;

use App\Models\EmailTemplate;

/**
 * Gli 8 template di default del legacy (getDefaultTemplates), replicati 1:1.
 * Il seeding è idempotente e non sovrascrive le personalizzazioni del tenant.
 */
class DefaultEmailTemplates
{
    /**
     * Ordine esplicito e completo dei template nella UI
     * (il legacy usava MySQL FIELD() con i codici fuori lista in testa).
     *
     * @var list<string>
     */
    public const ORDER = [
        'benvenuto',
        'scadenza_tessera',
        'scadenza_quota',
        'assemblea',
        'comunicazione',
        'rinnovo_tessera',
        'pagamento_quota',
        'nuova_preiscrizione',
    ];

    /**
     * @return array<string, array{nome: string, oggetto: string, corpo_html: string}>
     */
    public static function all(): array
    {
        return [
            'benvenuto' => [
                'nome' => 'Benvenuto nuovo socio',
                'oggetto' => 'Benvenuto in {NOME_ASSOCIAZIONE}, {NOME}!',
                'corpo_html' => '<h2>Benvenuto, {NOME} {COGNOME}!</h2><p>Siamo lieti di accoglierti come nuovo socio di <strong>{NOME_ASSOCIAZIONE}</strong>.</p><p>Il tuo numero socio è: <strong>{NUMERO_SOCIO}</strong></p><p>Data iscrizione: {DATA_ISCRIZIONE}</p><p>Per qualsiasi informazione, contattaci a {EMAIL_ASSOCIAZIONE}.</p>',
            ],
            'scadenza_tessera' => [
                'nome' => 'Promemoria scadenza tessera',
                'oggetto' => 'La tua tessera {NUMERO_TESSERA} sta per scadere',
                'corpo_html' => '<h2>Ciao {NOME},</h2><p>Ti ricordiamo che la tua tessera n. <strong>{NUMERO_TESSERA}</strong> presso {NOME_ASSOCIAZIONE} scadrà il <strong>{DATA_SCADENZA}</strong>.</p><p>Ti invitiamo a rinnovarla al più presto. Per informazioni contattaci a {EMAIL_ASSOCIAZIONE}.</p>',
            ],
            'scadenza_quota' => [
                'nome' => 'Promemoria quota da pagare',
                'oggetto' => 'Promemoria: quota associativa da pagare',
                'corpo_html' => '<h2>Ciao {NOME},</h2><p>Ti ricordiamo che la quota associativa di <strong>€{IMPORTO}</strong> per {NOME_ASSOCIAZIONE} risulta ancora da pagare.</p><p>Scadenza: <strong>{DATA_SCADENZA}</strong></p><p>Per informazioni contattaci a {EMAIL_ASSOCIAZIONE}.</p>',
            ],
            'assemblea' => [
                'nome' => 'Convocazione assemblea',
                'oggetto' => 'Convocazione: {TITOLO_EVENTO}',
                'corpo_html' => '<h2>Convocazione Assemblea</h2><p>Caro/a {NOME} {COGNOME},</p><p>Sei convocato/a all\'assemblea <strong>{TITOLO_EVENTO}</strong>.</p><p>Data: <strong>{DATA_EVENTO}</strong><br>Luogo: <strong>{LUOGO_EVENTO}</strong></p><p>La tua partecipazione è importante. Per informazioni contattaci a {EMAIL_ASSOCIAZIONE}.</p>',
            ],
            'comunicazione' => [
                'nome' => 'Comunicazione generica',
                'oggetto' => '{NOME_ASSOCIAZIONE} — Comunicazione',
                'corpo_html' => '<p>Caro/a {NOME} {COGNOME},</p><p>Questa è una comunicazione da parte di {NOME_ASSOCIAZIONE}.</p>',
            ],
            'rinnovo_tessera' => [
                'nome' => 'Conferma rinnovo tessera',
                'oggetto' => 'Tessera rinnovata — {NOME_ASSOCIAZIONE}',
                'corpo_html' => '<h2>Tessera rinnovata!</h2><p>Ciao {NOME},</p><p>La tua tessera n. <strong>{NUMERO_TESSERA}</strong> è stata rinnovata con successo.</p><p>Nuova scadenza: <strong>{DATA_SCADENZA}</strong></p><p>Grazie per il rinnovo!</p>',
            ],
            'pagamento_quota' => [
                'nome' => 'Conferma pagamento quota',
                'oggetto' => 'Pagamento registrato — {NOME_ASSOCIAZIONE}',
                'corpo_html' => '<h2>Pagamento ricevuto!</h2><p>Ciao {NOME},</p><p>Confermiamo la ricezione del pagamento di <strong>€{IMPORTO}</strong> in data {DATA_PAGAMENTO}.</p><p>Grazie per il tuo contributo a {NOME_ASSOCIAZIONE}!</p>',
            ],
            'nuova_preiscrizione' => [
                'nome' => 'Notifica nuova pre-iscrizione',
                'oggetto' => 'Nuova pre-iscrizione: {NOME} {COGNOME}',
                'corpo_html' => '<h2>Nuova richiesta di pre-iscrizione</h2><p>Un nuovo utente ha richiesto l\'iscrizione a <strong>{NOME_ASSOCIAZIONE}</strong>:</p><table style="border-collapse:collapse;width:100%;"><tr><td style="padding:6px 12px;border:1px solid #ddd;font-weight:bold;">Nome</td><td style="padding:6px 12px;border:1px solid #ddd;">{NOME} {COGNOME}</td></tr><tr><td style="padding:6px 12px;border:1px solid #ddd;font-weight:bold;">Email</td><td style="padding:6px 12px;border:1px solid #ddd;">{EMAIL}</td></tr><tr><td style="padding:6px 12px;border:1px solid #ddd;font-weight:bold;">Telefono</td><td style="padding:6px 12px;border:1px solid #ddd;">{TELEFONO}</td></tr><tr><td style="padding:6px 12px;border:1px solid #ddd;font-weight:bold;">Codice Fiscale</td><td style="padding:6px 12px;border:1px solid #ddd;">{CODICE_FISCALE}</td></tr></table><p style="margin-top:16px;">Accedi al pannello di gestione per approvare o rifiutare la richiesta.</p>',
            ],
        ];
    }

    /**
     * Seed idempotente dei template di default per il tenant (non sovrascrive gli esistenti).
     */
    public static function seedFor(string $associazioneId): void
    {
        $esistenti = EmailTemplate::forAssociazione($associazioneId)
            ->pluck('codice')
            ->all();

        foreach (self::all() as $codice => $tpl) {
            if (in_array($codice, $esistenti, true)) {
                continue;
            }

            EmailTemplate::create([
                'associazione_id' => $associazioneId,
                'codice' => $codice,
                'nome' => $tpl['nome'],
                'oggetto' => $tpl['oggetto'],
                'corpo_html' => $tpl['corpo_html'],
                'corpo_json' => null,
                'attivo' => true,
            ]);
        }
    }
}
