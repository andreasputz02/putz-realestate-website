<?php
// ============================================================
//  Justimmo -> Website
//
//  Liefert die in Justimmo gepflegten Objekte als JavaScript aus:
//      window.LISTINGS = [ ... ];
//
//  Dadurch muss am uebrigen Code nichts geaendert werden — die Seite
//  rendert die Objekte genau wie die bisher handgepflegte Liste.
//
//  Einbindung in den HTML-Seiten (Reihenfolge ist wichtig):
//      <script src="js/listings-data.js"></script>   <- Notfall-Liste
//      <script src="justimmo.php"></script>          <- ueberschreibt, wenn erreichbar
//      <script src="js/listings.js"></script>        <- zeigt an
//
//  Faellt die API aus, gibt diese Datei absichtlich NICHTS aus.
//  Dann bleibt die handgepflegte Liste stehen und die Seite
//  zeigt weiterhin Objekte, statt leer zu sein.
// ============================================================

require_once __DIR__ . '/justimmo-lib.php';

header('Content-Type: application/javascript; charset=utf-8');

// ------------------------------------------------------------
//  Konfiguration laden — fehlt sie, still aussteigen
// ------------------------------------------------------------
$konfigPfad = __DIR__ . '/justimmo-config.php';
if (!is_file($konfigPfad)) {
    echo "/* Justimmo: justimmo-config.php fehlt — es gilt die handgepflegte Liste. */\n";
    exit;
}
$konfig = require $konfigPfad;
$cacheSekunden = (int)($konfig['cache_sekunden'] ?? 900);
$anzahl        = min(100, max(1, (int)($konfig['anzahl'] ?? 100)));

// ------------------------------------------------------------
//  Zwischenspeicher: solange frisch, gar nicht erst anfragen
// ------------------------------------------------------------
if (is_file(JI_CACHE_DATEI) && (time() - filemtime(JI_CACHE_DATEI)) < $cacheSekunden) {
    $roh = file_get_contents(JI_CACHE_DATEI);
    if ($roh !== false && $roh !== '') {
        echo "window.LISTINGS = " . $roh . ";\n";
        exit;
    }
}

// ------------------------------------------------------------
//  Ablauf
// ------------------------------------------------------------
$xmlRoh = ji_abrufen('objekt/list', [
    'showDetails' => 1,
    'limit'       => $anzahl,
    'culture'     => 'de',
    'picturesize' => 'big',
], $konfig);

if ($xmlRoh === null) {
    echo "/* Justimmo nicht erreichbar — es gilt die handgepflegte Liste. */\n";
    exit;
}

$objekte = ji_umwandeln($xmlRoh);

if (!$objekte) {
    echo "/* Justimmo lieferte keine Objekte — es gilt die handgepflegte Liste. */\n";
    exit;
}

$json = json_encode($objekte, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// Zwischenspeicher aktualisieren
if (!is_dir(dirname(JI_CACHE_DATEI))) {
    @mkdir(dirname(JI_CACHE_DATEI), 0755, true);
}
@file_put_contents(JI_CACHE_DATEI, $json, LOCK_EX);

echo "window.LISTINGS = " . $json . ";\n";
