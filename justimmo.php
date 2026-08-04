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

header('Content-Type: application/javascript; charset=utf-8');

const JI_BASIS      = 'https://api.justimmo.at/rest/v1/';
const JI_CACHE_DATEI = __DIR__ . '/data/justimmo-cache.json';

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
//  Abruf bei Justimmo
// ------------------------------------------------------------
function ji_abrufen(string $pfad, array $parameter, array $konfig): ?string
{
    $url = JI_BASIS . ltrim($pfad, '/') . '?' . http_build_query($parameter);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_USERPWD        => $konfig['benutzer'] . ':' . $konfig['passwort'],
        CURLOPT_HTTPHEADER     => ['Accept: application/xml'],
    ]);
    $antwort = curl_exec($ch);
    $status  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($antwort !== false && $status === 200) ? $antwort : null;
}

// ------------------------------------------------------------
//  Hilfsfunktionen zum Auslesen der XML-Felder
//
//  Justimmo liefert XML "lose angelehnt an OpenImmo". Die genauen
//  Feldnamen koennen je nach Konto abweichen, deshalb wird pro Wert
//  eine Liste moeglicher Namen durchprobiert.
// ------------------------------------------------------------
function ji_ersterWert(SimpleXMLElement $knoten, array $pfade): string
{
    foreach ($pfade as $p) {
        $treffer = @$knoten->xpath($p);
        if ($treffer && trim((string)$treffer[0]) !== '') {
            return trim((string)$treffer[0]);
        }
    }
    return '';
}

function ji_alleWerte(SimpleXMLElement $knoten, array $pfade): array
{
    foreach ($pfade as $p) {
        $treffer = @$knoten->xpath($p);
        if ($treffer) {
            $werte = array_filter(array_map(fn($t) => trim((string)$t), $treffer));
            if ($werte) return array_values($werte);
        }
    }
    return [];
}

function ji_preisFormat(string $wert): string
{
    $zahl = (float)str_replace([' ', '.', ','], ['', '', '.'], $wert);
    if ($zahl <= 0) return 'Auf Anfrage';
    return '€ ' . number_format($zahl, 0, ',', '.');
}

function ji_flaecheFormat(string $wert): string
{
    $zahl = (float)str_replace(',', '.', $wert);
    return $zahl > 0 ? rtrim(rtrim(number_format($zahl, 1, ',', '.'), '0'), ',') . ' m²' : '';
}

function ji_kennung(string $titel, string $id): string
{
    $s = mb_strtolower($titel . '-' . $id, 'UTF-8');
    $s = strtr($s, ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-') ?: 'objekt-' . $id;
}

// ------------------------------------------------------------
//  XML -> Format der Website
// ------------------------------------------------------------
function ji_umwandeln(string $xmlRoh): array
{
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlRoh);
    if ($xml === false) return [];

    // Objektknoten finden. Justimmo antwortet als
    //   <justimmo><query-result><count>N</count> ... </query-result></justimmo>
    // Wie die Objektknoten darin heissen, ist je nach Konto unterschiedlich,
    // deshalb: erst die bekannten Namen, sonst schlicht alle Kinder von
    // <query-result> ausser <count>.
    $knoten = $xml->xpath('//immobilie') ?: $xml->xpath('//objekt') ?: [];
    if (!$knoten) {
        foreach ($xml->xpath('//query-result/*') ?: [] as $kind) {
            if ($kind->getName() !== 'count' && $kind->count() > 0) {
                $knoten[] = $kind;
            }
        }
    }
    $ergebnis = [];

    foreach ($knoten as $o) {
        $id = ji_ersterWert($o, ['@id', 'id', 'objekt_id', 'verwaltung_techn/objektnr_extern']);
        $titel = ji_ersterWert($o, ['objekttitel', 'titel', 'freitexte/objekttitel', 'ueberschrift']);
        if ($titel === '') $titel = 'Immobilie';

        $ort = ji_ersterWert($o, ['geo/ort', 'ort', 'adresse/ort']);
        $plz = ji_ersterWert($o, ['geo/plz', 'plz', 'adresse/plz']);
        $lage = trim($plz . ' ' . $ort) ?: 'Österreich';

        $kauf  = ji_ersterWert($o, ['preise/kaufpreis', 'kaufpreis', 'preis']);
        $miete = ji_ersterWert($o, ['preise/nettomiete', 'preise/kaltmiete', 'miete']);
        $istMiete = ($miete !== '' && $kauf === '');
        $preisRoh = $istMiete ? $miete : $kauf;

        $flaeche = ji_ersterWert($o, ['flaechen/wohnflaeche', 'wohnflaeche', 'flaechen/nutzflaeche', 'nutzflaeche', 'flaeche']);
        $zimmer  = ji_ersterWert($o, ['flaechen/anzahl_zimmer', 'anzahl_zimmer', 'zimmer']);
        $baeder  = ji_ersterWert($o, ['flaechen/anzahl_badezimmer', 'anzahl_badezimmer', 'badezimmer']);

        $texte = ji_alleWerte($o, [
            'freitexte/objektbeschreibung', 'objektbeschreibung',
            'freitexte/lage', 'beschreibung',
        ]);
        if (!$texte) $texte = ['Details zu diesem Objekt erhalten Sie gerne auf Anfrage.'];
        // Absaetze innerhalb eines Textes auftrennen
        $absaetze = [];
        foreach ($texte as $t) {
            foreach (preg_split('/\R{2,}/', $t) as $teil) {
                $teil = trim($teil);
                if ($teil !== '') $absaetze[] = $teil;
            }
        }

        $bilder = ji_alleWerte($o, [
            'anhaenge/anhang/daten/pfad', 'bilder/bild/pfad', 'bilder/bild', 'anhang/daten/pfad',
        ]);
        $bilder = array_values(array_filter($bilder, fn($b) => str_starts_with($b, 'http')));

        $ergebnis[] = [
            'id'          => ji_kennung($titel, $id),
            'justimmoId'  => $id,
            'title'       => $titel,
            'type'        => $istMiete ? 'miete' : 'kauf',
            'price'       => ji_preisFormat($preisRoh),
            'location'    => $lage,
            'mapQuery'    => $lage,
            'area'        => ji_flaecheFormat($flaeche) ?: '–',
            'rooms'       => $zimmer !== '' ? $zimmer : '–',
            'baths'       => $baeder !== '' ? $baeder : '–',
            'gradient'    => 'linear-gradient(135deg,#2c2822,#0f0e0c)',
            'images'      => $bilder,
            'description' => array_slice($absaetze, 0, 4),
            'video'       => null,
        ];
    }

    return $ergebnis;
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
