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
//  Die Objekte aus Justimmo werden VOR die handgepflegten gestellt,
//  nicht an deren Stelle. Beide Quellen erscheinen also gemeinsam.
//
//  Einbindung in den HTML-Seiten (Reihenfolge ist wichtig):
//      <script src="js/listings-data.js"></script>   <- handgepflegte Objekte
//      <script src="justimmo.php"></script>          <- ergaenzt um Justimmo
//      <script src="js/listings.js"></script>        <- zeigt an
//
//  Faellt die API aus, gibt diese Datei absichtlich NICHTS aus.
//  Dann bleibt die handgepflegte Liste stehen und die Seite
//  zeigt weiterhin Objekte, statt leer zu sein.
// ============================================================

require_once __DIR__ . '/justimmo-lib.php';

header('Content-Type: application/javascript; charset=utf-8');
// Objektdaten aendern sich laufend — der Browser darf sie nicht einfrieren.
// Wie oft wirklich bei Justimmo angefragt wird, steuert der Cache unten.
header('Cache-Control: no-store, must-revalidate');

/**
 * Baut die Zeile, die im Browser landet.
 *
 * Die Justimmo-Objekte kommen nach vorne, die handgepflegten dahinter.
 * Traegt ein handgepflegtes Objekt dieselbe Kennung wie eines aus
 * Justimmo, gewinnt Justimmo — sonst stuende es doppelt auf der Seite.
 */
function ji_ausgabe(string $json): string
{
    return "window.LISTINGS = (function (ausJustimmo, vonHand) {\n"
         . "  var bekannt = {};\n"
         . "  ausJustimmo.forEach(function (o) { bekannt[o.id] = true; });\n"
         . "  return ausJustimmo.concat(vonHand.filter(function (o) { return !bekannt[o.id]; }));\n"
         . "})(" . $json . ", window.LISTINGS || []);\n";
}

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
//  Zwischenspeicher
//
//  Drei Faelle, damit Aenderungen in Justimmo von selbst ankommen,
//  ohne dass jemand auf die Schnittstelle warten muss:
//
//    juenger als 60 s   -> aus dem Speicher, keine Anfrage
//    60 s bis Ablauf    -> aus dem Speicher, danach im Hintergrund
//                          neu holen. Der Besucher wartet nicht,
//                          der naechste sieht den neuen Stand.
//    aelter als Ablauf  -> jetzt holen, sonst waeren die Daten alt
//
//  Mit ?frisch=1 laesst sich das Holen erzwingen, hoechstens
//  einmal pro Minute.
// ------------------------------------------------------------
define('JI_FRISCH_SEKUNDEN', 60);   // so lange gilt der Speicher als aktuell

$alter    = is_file(JI_CACHE_DATEI) ? time() - filemtime(JI_CACHE_DATEI) : PHP_INT_MAX;
$erzwingen = isset($_GET['frisch']) && $alter > JI_FRISCH_SEKUNDEN;
$roh      = $alter < PHP_INT_MAX ? file_get_contents(JI_CACHE_DATEI) : false;
$brauchbar = ($roh !== false && $roh !== '');

if (!$erzwingen && $brauchbar && $alter < JI_FRISCH_SEKUNDEN) {
    echo ji_ausgabe($roh);
    exit;
}

// Speicher ist da, aber nicht mehr taufrisch: erst ausliefern, dann
// im Hintergrund erneuern. Das geht nur, wenn die Verbindung zum
// Besucher vorher geschlossen werden kann — sonst wartet er doch.
$imHintergrund = false;
if (!$erzwingen && $brauchbar && $alter < $cacheSekunden && function_exists('fastcgi_finish_request')) {
    echo ji_ausgabe($roh);
    // Datum vorziehen, damit nicht mehrere Aufrufe gleichzeitig holen.
    @touch(JI_CACHE_DATEI);
    ignore_user_abort(true);
    fastcgi_finish_request();
    $imHintergrund = true;
}

// Ohne fastcgi_finish_request bleibt es beim bisherigen Verhalten:
// solange der Speicher gilt, wird er ausgeliefert.
if (!$imHintergrund && !$erzwingen && $brauchbar && $alter < $cacheSekunden) {
    echo ji_ausgabe($roh);
    exit;
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
    if (!$imHintergrund) echo "/* Justimmo nicht erreichbar — es gilt die handgepflegte Liste. */\n";
    exit;
}

$objekte = ji_umwandeln($xmlRoh);

if (!$objekte) {
    if (!$imHintergrund) echo "/* Justimmo lieferte keine Objekte — es gilt die handgepflegte Liste. */\n";
    exit;
}

$json = json_encode($objekte, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// Zwischenspeicher aktualisieren
if (!is_dir(dirname(JI_CACHE_DATEI))) {
    @mkdir(dirname(JI_CACHE_DATEI), 0755, true);
}
@file_put_contents(JI_CACHE_DATEI, $json, LOCK_EX);

// Im Hintergrund ist die Antwort laengst raus — dann nichts mehr senden.
if (!$imHintergrund) {
    echo ji_ausgabe($json);
}
