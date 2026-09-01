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
function ji_ausgabe(string $json, string $vermerk = ''): string
{
    return ($vermerk !== '' ? "/* Justimmo: $vermerk */\n" : '')
         . "window.LISTINGS = (function (ausJustimmo, vonHand) {\n"
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
//
//  WICHTIG: Das Alter haengt am Aenderungsdatum der Speicherdatei.
//  Die darf deshalb nur geschrieben werden, wenn wirklich neue Daten
//  vorliegen. Wurde sie schon beim START des Hintergrundlaufs
//  angefasst, galt der Speicher nach jedem Aufruf wieder als frisch —
//  bricht der Lauf dann ab, altert er nie mehr und die Objektliste
//  friert dauerhaft ein. Gegen mehrere gleichzeitige Laeufe dient
//  darum eine eigene Sperrdatei.
// ------------------------------------------------------------
define('JI_FRISCH_SEKUNDEN', 60);    // so lange gilt der Speicher als aktuell
define('JI_SPERRE_SEKUNDEN', 120);   // so lange laeuft hoechstens ein Hintergrundlauf
const JI_SPERR_DATEI = __DIR__ . '/data/justimmo-laeuft.lock';

/**
 * Beendet die Antwort an den Besucher, laesst das Skript aber
 * weiterlaufen. Der Name der Funktion haengt davon ab, wie PHP
 * eingebunden ist: LiteSpeed (Hostinger) bringt litespeed_,
 * PHP-FPM bringt fastcgi_. Fehlen beide, geht es nicht.
 */
function ji_antwortAbschliessen(): bool
{
    foreach (['litespeed_finish_request', 'fastcgi_finish_request'] as $fn) {
        if (function_exists($fn)) { $fn(); return true; }
    }
    return false;
}

$alter     = is_file(JI_CACHE_DATEI) ? time() - filemtime(JI_CACHE_DATEI) : PHP_INT_MAX;
$erzwingen = isset($_GET['frisch']) && $alter > JI_FRISCH_SEKUNDEN;
$roh       = $alter < PHP_INT_MAX ? file_get_contents(JI_CACHE_DATEI) : false;
$brauchbar = ($roh !== false && $roh !== '');

if (!$erzwingen && $brauchbar && $alter < JI_FRISCH_SEKUNDEN) {
    echo ji_ausgabe($roh, "Stand {$alter} s, frisch");
    exit;
}

// Speicher ist da, aber nicht mehr taufrisch: erst ausliefern, dann
// im Hintergrund erneuern. Nur ein Lauf gleichzeitig — die Sperrdatei
// haelt die uebrigen Aufrufe zurueck, ohne den Speicher zu verjuengen.
$imHintergrund = false;
if (!$erzwingen && $brauchbar && $alter < $cacheSekunden) {
    $sperrAlter = is_file(JI_SPERR_DATEI) ? time() - filemtime(JI_SPERR_DATEI) : PHP_INT_MAX;

    if ($sperrAlter > JI_SPERRE_SEKUNDEN) {
        if (!is_dir(dirname(JI_SPERR_DATEI))) @mkdir(dirname(JI_SPERR_DATEI), 0755, true);
        @touch(JI_SPERR_DATEI);
        echo ji_ausgabe($roh, "Stand {$alter} s, wird im Hintergrund erneuert");
        ignore_user_abort(true);
        @set_time_limit(60);
        $imHintergrund = ji_antwortAbschliessen();
        // Laesst sich die Antwort nicht abschliessen, wuerde der
        // Besucher auf die Schnittstelle warten. Dann lieber nicht
        // holen — spaetestens nach Ablauf greift der harte Weg unten.
        if (!$imHintergrund) { @unlink(JI_SPERR_DATEI); exit; }
    } else {
        echo ji_ausgabe($roh, "Stand {$alter} s, Erneuerung laeuft bereits");
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
    @unlink(JI_SPERR_DATEI);
    if (!$imHintergrund) echo "/* Justimmo nicht erreichbar — es gilt die handgepflegte Liste. */\n";
    exit;
}

$objekte = ji_umwandeln($xmlRoh);

if (!$objekte) {
    @unlink(JI_SPERR_DATEI);
    if (!$imHintergrund) echo "/* Justimmo lieferte keine Objekte — es gilt die handgepflegte Liste. */\n";
    exit;
}

$json = json_encode($objekte, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// Zwischenspeicher aktualisieren
if (!is_dir(dirname(JI_CACHE_DATEI))) {
    @mkdir(dirname(JI_CACHE_DATEI), 0755, true);
}
@file_put_contents(JI_CACHE_DATEI, $json, LOCK_EX);
@unlink(JI_SPERR_DATEI);

// Im Hintergrund ist die Antwort laengst raus — dann nichts mehr senden.
if (!$imHintergrund) {
    echo ji_ausgabe($json, 'soeben bei Justimmo geholt');
}
