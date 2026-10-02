<?php
// ============================================================
//  Objekte fuer die Server-Seiten (objekt.php, sitemap-immobilien.php)
//
//  Dieselben Objekte, die justimmo.php an den Browser liefert:
//  zuerst die aus Justimmo (aus dem Zwischenspeicher, den justimmo.php
//  laufend erneuert), dahinter die handgepflegten aus js/listings-data.js.
//  Hier wird NICHT bei Justimmo angefragt — eine Objektseite soll nie auf
//  die Schnittstelle warten. Ist der Speicher leer, gibt es eben nur die
//  handgepflegten Objekte.
// ============================================================

require_once __DIR__ . '/justimmo-lib.php';   // fuer JI_CACHE_DATEI

const OBJ_BASIS = 'https://putz-realestate.at/';

/**
 * Die handgepflegte Liste steht als JavaScript in js/listings-data.js.
 * Sie ist einfach genug gebaut (Objekte mit Schluesseln ohne
 * Anfuehrungszeichen, Kommentare, Komma am Ende), um sie hier in JSON zu
 * verwandeln. Geht das schief, bleibt die Liste leer — die Seite
 * funktioniert dann trotzdem fuer alle Justimmo-Objekte.
 */
function obj_vonHand(): array
{
    $js = @file_get_contents(__DIR__ . '/js/listings-data.js');
    if ($js === false) return [];
    $a = strpos($js, '[');
    $e = strrpos($js, ']');
    if ($a === false || $e === false || $e < $a) return [];
    $s = substr($js, $a, $e - $a + 1);
    $s = preg_replace('~^\s*//.*$~m', '', $s);                       // Kommentarzeilen
    // Schluessel: jeder steht am Zeilenanfang ("    title: ..."). So bleibt ein
    // Doppelpunkt mitten in einem Text ("Lage: ruhig") unberuehrt.
    $s = preg_replace('~^(\s*)([A-Za-z_][A-Za-z0-9_]*)\s*:~m', '$1"$2":', $s);
    $s = preg_replace('~,(\s*[\]}])~', '$1', $s);                     // Komma am Ende
    $liste = json_decode($s, true);
    return is_array($liste) ? $liste : [];
}

function obj_alle(): array
{
    $ji = [];
    $roh = is_file(JI_CACHE_DATEI) ? @file_get_contents(JI_CACHE_DATEI) : false;
    if ($roh) {
        $d = json_decode($roh, true);
        if (is_array($d)) $ji = $d;
    }
    $bekannt = array_flip(array_map(fn($o) => (string)($o['id'] ?? ''), $ji));
    $hand = array_values(array_filter(obj_vonHand(), fn($o) => !isset($bekannt[(string)($o['id'] ?? '')])));
    return array_merge($ji, $hand);
}

function obj_finden(string $slug): ?array
{
    foreach (obj_alle() as $o) {
        if ((string)($o['id'] ?? '') === $slug) return $o;
    }
    return null;
}

function obj_url(array $o): string
{
    return OBJ_BASIS . 'immobilie/' . rawurlencode((string)$o['id']);
}

/** Bild-Adresse absolut machen (handgepflegte Objekte haben relative Pfade). */
function obj_absolut(string $pfad): string
{
    return preg_match('~^https?://~', $pfad) ? $pfad : OBJ_BASIS . ltrim($pfad, '/');
}

/**
 * Passende Ortsseite zum Objekt, damit Objekt und Ort sich gegenseitig
 * stuetzen. "2123 Unterolberndorf, Mistelbach" -> immobilien-verkaufen-mistelbach,
 * "1190 Wien, Döbling" -> immobilien-verkaufen-wien-doebling.
 * Liefert [Adresse, Anzeigename] oder null.
 */
function obj_ortsseite(array $o): ?array
{
    $slug = function (string $s): string {
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        return trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    };
    $lage = (string)($o['location'] ?? '');
    $plz  = (string)($o['plz'] ?? '');
    $teile = array_map('trim', explode(',', $lage));
    $bezirk = count($teile) > 1 ? end($teile) : '';
    $kandidaten = [];
    if (preg_match('/^1(\d\d)0$/', $plz) && $bezirk !== '') {
        $kandidaten[] = ['wien-' . $slug($bezirk), $bezirk];
    }
    if ($bezirk !== '') $kandidaten[] = [$slug($bezirk), 'Bezirk ' . $bezirk];
    // Ort selbst ("2120 Wolkersdorf im Weinviertel" -> wolkersdorf)
    $ort = trim(preg_replace('/^\d{4}\s*/', '', $teile[0] ?? ''));
    if ($ort !== '') {
        $kandidaten[] = [$slug($ort), $ort];
        $kandidaten[] = [$slug(explode(' ', $ort)[0]), $ort];
    }
    foreach ($kandidaten as [$s, $name]) {
        if ($s !== '' && is_file(__DIR__ . '/immobilien-verkaufen-' . $s . '.html')) {
            return ['immobilien-verkaufen-' . $s, $name];
        }
    }
    return null;
}
