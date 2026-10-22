<?php

/*
 * Radice del progetto usata come web root (hosting condiviso) SENZA
 * mod_rewrite: il .htaccess accanto non può far passare le richieste da
 * public/, quindi si rimanda il browser a public/ mantenendo percorso e
 * query. Con mod_rewrite attivo (quasi tutti gli hosting Apache/cPanel)
 * questo file non viene mai eseguito.
 */

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$percorso = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
$query = (string) (parse_url($uri, PHP_URL_QUERY) ?: '');

$relativo = $base !== '' && str_starts_with($percorso, $base) ? substr($percorso, strlen($base)) : $percorso;
if ($relativo === '' || $relativo[0] !== '/') {
    $relativo = '/'.$relativo;
}

if (str_starts_with($relativo, '/public/')) {
    http_response_code(404);
    exit;
}

header('Location: '.$base.'/public'.$relativo.($query !== '' ? '?'.$query : ''), true, 302);
exit;
