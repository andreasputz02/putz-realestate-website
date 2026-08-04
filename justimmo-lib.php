<?php
// ============================================================
//  Justimmo — gemeinsame Funktionen
//
//  Wird von justimmo.php (Ausgabe fuer die Website) und von
//  admin/justimmo-test.php (Kontrollseite) verwendet, damit die
//  Kontrollseite garantiert dasselbe Ergebnis zeigt wie die Seite.
// ============================================================

const JI_BASIS       = 'https://api.justimmo.at/rest/v1/';
const JI_CACHE_DATEI = __DIR__ . '/data/justimmo-cache.json';

// ------------------------------------------------------------
//  Abruf bei Justimmo
// ------------------------------------------------------------
function ji_abrufen(string $pfad, array $parameter, array $konfig, ?int &$status = null, ?string &$fehler = null): ?string
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
    $status  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $fehler  = curl_error($ch) ?: null;
    curl_close($ch);

    return ($antwort !== false && $status === 200) ? $antwort : null;
}

// ------------------------------------------------------------
//  Hilfsfunktionen zum Auslesen der XML-Felder
//
//  Justimmo liefert XML "lose angelehnt an OpenImmo". Die genauen
//  Feldnamen koennen je nach Konto abweichen, deshalb wird pro Wert
//  eine Liste moeglicher Pfade durchprobiert.
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
    $wert = trim($wert);
    // Justimmo liefert reine Maschinenzahlen ("200000" oder "200000.00").
    // Der Punkt ist dort ein Dezimaltrennzeichen, kein Tausenderpunkt —
    // deshalb nur bei deutscher Schreibweise umformen.
    if (preg_match('/^\d+(\.\d+)?$/', $wert)) {
        $zahl = (float)$wert;
    } else {
        $zahl = (float)str_replace([' ', '.', ','], ['', '', '.'], $wert);
    }
    if ($zahl <= 0) return 'Auf Anfrage';
    return '€ ' . number_format($zahl, 0, ',', '.');
}

function ji_flaecheFormat(string $wert): string
{
    $zahl = (float)str_replace(',', '.', trim($wert));
    return $zahl > 0 ? rtrim(rtrim(number_format($zahl, 1, ',', '.'), '0'), ',') . ' m²' : '';
}

function ji_ganzzahl(string $wert): string
{
    $zahl = (float)str_replace(',', '.', trim($wert));
    if ($zahl <= 0) return '';
    // 5 statt 5,0 — aber 2,5 Zimmer bleiben erhalten
    return rtrim(rtrim(number_format($zahl, 1, ',', ''), '0'), ',');
}

function ji_kennung(string $titel, string $id): string
{
    $s = mb_strtolower($titel . '-' . $id, 'UTF-8');
    $s = strtr($s, ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    return $s !== '' ? $s : 'objekt-' . $id;
}

// ------------------------------------------------------------
//  Objektknoten in der Antwort finden
//
//  Justimmo antwortet als
//    <justimmo><query-result><count>N</count><immobilie>…</immobilie></query-result></justimmo>
// ------------------------------------------------------------
function ji_objektKnoten(SimpleXMLElement $xml): array
{
    $knoten = $xml->xpath('//immobilie') ?: $xml->xpath('//objekt') ?: [];
    if (!$knoten) {
        foreach ($xml->xpath('//query-result/*') ?: [] as $kind) {
            if ($kind->getName() !== 'count' && $kind->count() > 0) {
                $knoten[] = $kind;
            }
        }
    }
    return $knoten;
}

// ------------------------------------------------------------
//  XML -> Format der Website
// ------------------------------------------------------------
function ji_umwandeln(string $xmlRoh): array
{
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlRoh);
    if ($xml === false) return [];

    $ergebnis = [];

    foreach (ji_objektKnoten($xml) as $o) {
        $id = ji_ersterWert($o, [
            '@id',
            'verwaltung_techn/objektnr_extern',
            'verwaltung_techn/objektnr_intern',
            'user_defined_simplefield[@feldname="objekt_id"]',
            'id', 'objekt_id',
        ]);

        $titel = ji_ersterWert($o, ['freitexte/objekttitel', 'objekttitel', 'titel', 'ueberschrift']);
        if ($titel === '') $titel = 'Immobilie';

        $ort     = ji_ersterWert($o, ['geo/ort', 'ort', 'adresse/ort']);
        $plz     = ji_ersterWert($o, ['geo/plz', 'plz', 'adresse/plz']);
        $strasse = ji_ersterWert($o, ['geo/strasse', 'strasse']);
        $hausnr  = ji_ersterWert($o, ['geo/hausnummer', 'hausnummer']);

        // "Wien 3., Landstraße" -> "Landstraße"
        $bezirk = ji_ersterWert($o, ['geo/user_defined_simplefield[@feldname="politischer_bezirk"]']);
        if (str_contains($bezirk, ',')) {
            $bezirk = trim(substr($bezirk, strrpos($bezirk, ',') + 1));
        }

        // Anzeige: "1030 Wien, Landstraße" — wie bei den handgepflegten Objekten
        $lage = trim($plz . ' ' . $ort);
        if ($bezirk !== '' && $bezirk !== $ort) $lage = trim($lage . ', ' . $bezirk, ', ');
        if ($lage === '') $lage = 'Österreich';

        // Karte: so genau wie moeglich, daher mit Strasse
        $karte = trim(trim($strasse . ' ' . $hausnr) . ', ' . trim($plz . ' ' . $ort), ' ,');
        if ($karte === '') $karte = $lage;

        // Kauf oder Miete steht als Merkmal in der Vermarktungsart —
        // verlaesslicher als der Umweg ueber die gesetzten Preisfelder.
        $kaufFlag  = ji_ersterWert($o, ['objektkategorie/vermarktungsart/@KAUF']);
        $mieteFlag = ji_ersterWert($o, ['objektkategorie/vermarktungsart/@MIETE_PACHT']);

        $kauf  = ji_ersterWert($o, ['preise/kaufpreis', 'kaufpreis', 'preis']);
        $miete = ji_ersterWert($o, ['preise/nettomiete', 'preise/kaltmiete', 'preise/warmmiete', 'miete']);

        if ($kaufFlag === '1' || $mieteFlag === '1') {
            $istMiete = ($mieteFlag === '1' && $kaufFlag !== '1');
        } else {
            $istMiete = ($miete !== '' && $kauf === '');
        }
        $preisRoh = $istMiete ? ($miete ?: $kauf) : ($kauf ?: $miete);

        $flaeche = ji_ersterWert($o, ['flaechen/wohnflaeche', 'wohnflaeche', 'flaechen/nutzflaeche', 'nutzflaeche', 'flaeche']);
        $zimmer  = ji_ersterWert($o, ['flaechen/anzahl_zimmer', 'anzahl_zimmer', 'zimmer']);
        $baeder  = ji_ersterWert($o, ['flaechen/anzahl_badezimmer', 'anzahl_badezimmer', 'badezimmer']);

        $texte = ji_alleWerte($o, [
            'freitexte/objektbeschreibung', 'objektbeschreibung',
            'freitexte/dreizeiler', 'freitexte/lage', 'beschreibung',
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
            'anhaenge/anhang/daten/pfad',
            'anhang/daten/pfad',
            'bilder/bild/pfad',
            'bilder/bild',
        ]);
        $bilder = array_values(array_filter($bilder, fn($b) => str_starts_with($b, 'http')));

        $ergebnis[] = [
            'id'          => ji_kennung($titel, $id),
            'justimmoId'  => $id,
            'title'       => $titel,
            'type'        => $istMiete ? 'miete' : 'kauf',
            'price'       => ji_preisFormat($preisRoh),
            'location'    => $lage,
            'mapQuery'    => $karte,
            'area'        => ji_flaecheFormat($flaeche) ?: '–',
            'rooms'       => ji_ganzzahl($zimmer) ?: '–',
            'baths'       => ji_ganzzahl($baeder) ?: '–',
            'gradient'    => 'linear-gradient(135deg,#2c2822,#0f0e0c)',
            'images'      => $bilder,
            'description' => array_slice($absaetze, 0, 4),
            'video'       => null,
        ];
    }

    return $ergebnis;
}
