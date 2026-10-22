{{--
    Informativa predefinita (artt. 13-14 GDPR) compilata con i dati dell'associazione:
    usata quando l'associazione non ha ancora scritto la propria e come base
    modificabile nella pagina Configurazioni → Privacy Policy.
--}}
@php
    $sede = trim(implode(' ', array_filter([$associazione->indirizzo, $associazione->cap, $associazione->citta, $associazione->provincia ? '('.$associazione->provincia.')' : null])));
    $consensiFacoltativi = $associazione->moduli()->elencoConsensi();
    $titolare = implode(', ', array_filter([
        $associazione->nome,
        $sede !== '' ? $sede : null,
        $associazione->codice_fiscale ? 'codice fiscale '.$associazione->codice_fiscale : null,
    ]));
    $contatti = implode(', ', array_filter([$associazione->email, $associazione->telefono]));
@endphp
<h3>Informativa sul trattamento dei dati personali</h3>
<p>Ai sensi degli articoli 13 e 14 del Regolamento (UE) 2016/679 (GDPR), {{ $associazione->nome }} informa i propri soci e chi richiede l'iscrizione su come tratta i dati personali.</p>

<h4>Titolare del trattamento</h4>
<p>{{ $titolare }}. Contatti: {{ $contatti }}.</p>

<h4>Dati trattati</h4>
<p>Dati anagrafici e di contatto, codice fiscale, dati relativi al tesseramento, alle quote e ai pagamenti, alla partecipazione ad attività ed eventi, ed eventuali altri dati richiesti nel modulo di iscrizione.</p>

<h4>Finalità e basi giuridiche</h4>
<ul>
    <li>Gestione della richiesta di iscrizione e del rapporto associativo (tesseramento, quote, convocazioni, attività): esecuzione del rapporto associativo, art. 6.1.b GDPR.</li>
    <li>Adempimenti previsti dalla legge, dallo statuto e dalla normativa fiscale e contabile (libro soci, ricevute, rendiconti): obbligo legale, art. 6.1.c GDPR.</li>
    @foreach ($consensiFacoltativi as $consenso)
        <li>{{ $consenso['testo'] }}: consenso, art. 6.1.a GDPR{{ $consenso['obbligatorio'] ? '' : ', facoltativo e revocabile in ogni momento' }}.</li>
    @endforeach
</ul>

<h4>Natura del conferimento</h4>
<p>Il conferimento dei dati necessari all'iscrizione è obbligatorio: senza di essi non è possibile accogliere la richiesta. I consensi facoltativi possono essere negati o revocati senza conseguenze sul rapporto associativo.</p>

<h4>Modalità e destinatari</h4>
<p>I dati sono trattati con strumenti informatici e cartacei da persone autorizzate dell'associazione, con misure di sicurezza adeguate. Possono essere comunicati a fornitori di servizi tecnici (gestionale, posta elettronica, pagamenti online) nominati responsabili del trattamento, e a enti e autorità quando richiesto dalla legge. Non sono diffusi né trasferiti fuori dall'Unione europea senza garanzie adeguate.</p>

<h4>Conservazione</h4>
<p>Per la durata del rapporto associativo e, dopo la sua cessazione, per il tempo previsto dagli obblighi di legge (di norma dieci anni per i documenti contabili). Le richieste di iscrizione non accolte sono cancellate entro dodici mesi.</p>

<h4>Diritti dell'interessato</h4>
<p>In ogni momento è possibile chiedere l'accesso ai propri dati, la rettifica, la cancellazione, la limitazione del trattamento, la portabilità e opporsi al trattamento (artt. 15-22 GDPR), scrivendo a {{ $associazione->email }}. È inoltre possibile proporre reclamo al Garante per la protezione dei dati personali (www.garanteprivacy.it).</p>
