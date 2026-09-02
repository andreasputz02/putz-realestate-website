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

// Objekte dieses Abgebers stehen auf der Website immer vorne. Es genuegt
// ein Teil des Namens, gross oder klein geschrieben ist egal. Leer
// lassen schaltet die Bevorzugung ab.
const JI_VORRANG_ABGEBER = '888koy';

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

/**
 * Meldet eine Objektanfrage an Justimmo.
 *
 * Justimmo legt daraus eine Anfrage beim Objekt an und den Interessenten
 * als Kontakt. Die Bestaetigung per E-Mail laeuft davon unabhaengig —
 * scheitert dieser Aufruf, geht die Anfrage trotzdem nicht verloren.
 *
 * Rueckgabe: [erfolg (bool), hinweis (string)] — der Hinweis landet im
 * Fehlerprotokoll, nicht beim Besucher.
 */
function ji_anfrageSenden(string $objektId, array $daten, array $konfig): array
{
    if ($objektId === '' || !ctype_digit($objektId)) {
        return [false, 'keine gueltige Objektnummer'];
    }

    $parameter = array_filter([
        'objekt_id' => $objektId,
        'vorname'   => $daten['vorname']  ?? '',
        'nachname'  => $daten['nachname'] ?? '',
        'email'     => $daten['email']    ?? '',
        'tel'       => $daten['telefon']  ?? '',
        'message'   => $daten['nachricht'] ?? '',
    ], fn($w) => $w !== '');

    $ch = curl_init(JI_BASIS . 'objekt/anfrage?' . http_build_query($parameter));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_USERPWD        => $konfig['benutzer'] . ':' . $konfig['passwort'],
    ]);
    $antwort = curl_exec($ch);
    $status  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $fehler  = curl_error($ch);
    curl_close($ch);

    if ($status === 200) return [true, 'ok'];
    return [false, 'HTTP ' . $status . ($fehler ? ' — ' . $fehler : '') . ' — ' . substr((string)$antwort, 0, 200)];
}

/**
 * Durchsucht ein Objekt nach einer Videoadresse — egal in welchem Feld.
 *
 * Der Freitext wird dabei ausgespart: ein Link, den jemand mitten in die
 * Objektbeschreibung geschrieben hat, ist meist nicht der Rundgang.
 * Geprueft werden sowohl Feldinhalte als auch Attribute.
 */
function ji_videoSuche(SimpleXMLElement $o): string
{
    $muster = '#https?://[^\s"\'<>]*(?:youtube\.com|youtu\.be|vimeo\.com)[^\s"\'<>]*#i';

    // Erst ausserhalb der Beschreibung suchen — dort stehen die
    // eigens dafuer vorgesehenen Felder, das ist die sichere Quelle.
    foreach (@$o->xpath('.//*[not(ancestor-or-self::freitexte)]') ?: [] as $el) {
        if (preg_match($muster, (string)$el, $t)) return html_entity_decode($t[0]);
        foreach ($el->attributes() ?? [] as $wert) {
            if (preg_match($muster, (string)$wert, $t)) return html_entity_decode($t[0]);
        }
    }

    // Sonst in der Beschreibung nachsehen: viele Objekte tragen den
    // Link nur dort im Fliesstext.
    foreach (@$o->xpath('.//freitexte//*') ?: [] as $el) {
        if (preg_match($muster, (string)$el, $t)) return html_entity_decode($t[0]);
    }
    return '';
}

/**
 * Ist das ein Hochkant-Video (YouTube Short)?
 *
 * YouTube verraet das Format nicht ueber die oEmbed-Schnittstelle — dort
 * steht immer 16:9. Aber: ruft man /shorts/KENNUNG auf, bleibt ein Short
 * dort stehen (200), waehrend ein Querformat-Video auf /watch umgeleitet
 * wird (303). Das ist ein verlaesslicher Unterschied.
 *
 * Im Zweifel Querformat — das ist der haeufigere Fall.
 */
function ji_istShort(string $kennung): bool
{
    $ch = curl_init('https://www.youtube.com/shorts/' . $kennung);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 5,
    ]);
    curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $status === 200;
}

/**
 * Macht aus der Video-Adresse das, was die Detailseite braucht.
 *
 * In Justimmo kann man entweder eine Datei hochladen oder einen Link
 * angeben. Eine hochgeladene Datei wird direkt abgespielt; ein Link zu
 * YouTube oder Vimeo wird als Rahmen eingebettet.
 *
 * Bei YouTube wird bewusst die Adresse ohne Werbe-Cookies verwendet.
 */
function ji_video(string $url, string $deckblatt): ?array
{
    $url = trim($url);
    if ($url === '' || !str_starts_with($url, 'http')) return null;

    if (preg_match('#youtube\.com/.*[?&]v=([A-Za-z0-9_-]{6,})#i', $url, $t)
        || preg_match('#youtu\.be/([A-Za-z0-9_-]{6,})#i', $url, $t)
        || preg_match('#youtube\.com/(?:embed|shorts)/([A-Za-z0-9_-]{6,})#i', $url, $t)) {
        return [
            'einbettung' => 'https://www.youtube-nocookie.com/embed/' . $t[1],
            'hochformat' => ji_istShort($t[1]),
        ];
    }

    if (preg_match('#vimeo\.com/(?:video/)?(\d+)#i', $url, $t)) {
        return ['einbettung' => 'https://player.vimeo.com/video/' . $t[1]];
    }

    $endung = strtolower(pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    if (in_array($endung, ['mp4', 'mov', 'm4v', 'webm'], true)) {
        return ['src' => $url, 'poster' => $deckblatt, 'orientation' => 'landscape'];
    }

    // Unbekannter Anbieter — als Rahmen versuchen, das klappt bei den meisten.
    return ['einbettung' => $url];
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
/**
 * Sucht den Abgeber eines Objekts.
 *
 * Justimmo legt die abgebende Firma je nach Konto an unterschiedlichen
 * Stellen ab — mal als Firma der Kontaktperson, mal als eigenes Feld,
 * mal am Projekt. Deshalb werden erst die ueblichen Pfade gefragt.
 */
function ji_abgeber(SimpleXMLElement $o): string
{
    return ji_ersterWert($o, [
        'user_defined_simplefield[@feldname="abgeber"]',
        'user_defined_simplefield[@feldname="auftraggeber"]',
        'user_defined_simplefield[@feldname="bautraeger"]',
        'user_defined_simplefield[@feldname="eigentuemer"]',
        'verwaltung_objekt/abgeber',
        'kontaktperson/firma',
        'kontaktperson/firma_zusatz',
        'projekt/name',
        'projekt/titel',
        'user_defined_simplefield[@feldname="projekt_name"]',
    ]);
}

/**
 * Steht das Objekt dem bevorzugten Abgeber zu?
 *
 * Der Name wird nicht nur im gefundenen Abgeberfeld gesucht, sondern im
 * gesamten Objekt. So greift die Bevorzugung auch dann, wenn Justimmo
 * den Abgeber an einer Stelle fuehrt, die oben nicht aufgezaehlt ist.
 */
function ji_hatVorrang(SimpleXMLElement $o, string $abgeber): bool
{
    if (JI_VORRANG_ABGEBER === '') return false;
    if (stripos($abgeber, JI_VORRANG_ABGEBER) !== false) return true;
    return stripos((string)$o->asXML(), JI_VORRANG_ABGEBER) !== false;
}

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

        // Fuer Anfragen ueber die Schnittstelle wird die INTERNE Nummer
        // gebraucht — eine reine Zahl. Die Nummer oben ("2439/15") ist die
        // externe Objektnummer und wird von /objekt/anfrage nicht akzeptiert.
        $objektId = '';
        foreach ([
            '@id',
            'user_defined_simplefield[@feldname="objekt_id"]',
            'verwaltung_techn/user_defined_simplefield[@feldname="objekt_id"]',
            'verwaltung_techn/objektnr_intern',
            'user_defined_simplefield[@feldname="justimmo_id"]',
            'objekt_id',
        ] as $pfad) {
            $wert = ji_ersterWert($o, [$pfad]);
            if ($wert !== '' && ctype_digit($wert)) { $objektId = $wert; break; }
        }

        // Objektart fuer den Filter — "Wohnung", "Haus", "Grundstueck" ...
        $objektart = ji_ersterWert($o, [
            'objektkategorie/user_defined_simplefield[@feldname="objektart_name"]',
            'objektkategorie/objektart/*[1]/name()',
        ]);
        if ($objektart === '') {
            // Rueckfall: der Elementname unter <objektart> ist die Art selbst,
            // z. B. <wohnung/> oder <haus/>.
            $arten = @$o->xpath('objektkategorie/objektart/*');
            if ($arten) $objektart = ucfirst($arten[0]->getName());
        }

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

        // Koordinaten liefert Justimmo gleich mit — damit laesst sich der
        // Umkreis auf der Detailseite genau um die Adresse zeichnen.
        $breite = ji_ersterWert($o, [
            'geo/geokoordinaten/@breitengrad',
            'geo/user_defined_simplefield[@feldname="geokoordinaten_breitengrad_exakt"]',
            'geo/user_defined_simplefield[@feldname="geokoordinaten_breitengrad"]',
        ]);
        $laenge = ji_ersterWert($o, [
            'geo/geokoordinaten/@laengengrad',
            'geo/user_defined_simplefield[@feldname="geokoordinaten_laengengrad_exakt"]',
            'geo/user_defined_simplefield[@feldname="geokoordinaten_laengengrad"]',
        ]);
        $lat = is_numeric($breite) ? (float)$breite : null;
        $lng = is_numeric($laenge) ? (float)$laenge : null;

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
        $grundflaeche = ji_ersterWert($o, ['flaechen/grundstuecksflaeche', 'grundstuecksflaeche', 'flaechen/grundflaeche', 'grundflaeche']);
        $zimmer  = ji_ersterWert($o, ['flaechen/anzahl_zimmer', 'anzahl_zimmer', 'zimmer']);
        $baeder  = ji_ersterWert($o, ['flaechen/anzahl_badezimmer', 'anzahl_badezimmer', 'badezimmer']);

        $texte = ji_alleWerte($o, [
            'freitexte/objektbeschreibung', 'objektbeschreibung',
            'freitexte/dreizeiler', 'freitexte/lage', 'beschreibung',
        ]);
        if (!$texte) $texte = ['Details zu diesem Objekt bekommst du gerne auf Anfrage.'];

        $absaetze = [];
        foreach ($texte as $t) {
            if (str_contains($t, '<')) {
                // Justimmo liefert die Beschreibung bereits formatiert.
                // Nur harmlose Tags stehen lassen — und deren Attribute
                // entfernen, damit nichts Ausfuehrbares durchrutscht.
                $sauber = strip_tags($t, '<p><br><strong><b><em><i><u><ul><ol><li>');
                $sauber = preg_replace('/<([a-z0-9]+)\s[^>]*>/i', '<$1>', $sauber);
                $sauber = trim($sauber);
                if ($sauber !== '') $absaetze[] = $sauber;
            } else {
                // Reiner Text: an Leerzeilen in Absaetze trennen.
                foreach (preg_split('/\R{2,}/', $t) as $teil) {
                    $teil = trim($teil);
                    if ($teil !== '') $absaetze[] = $teil;
                }
            }
        }

        // Anhaenge einmal durchgehen und nach Art trennen. Frueher wanderte
        // jeder Anhang in die Bilderliste — ein Video waere dort als
        // kaputtes Foto gelandet.
        $bilder = [];
        $videoUrl = '';
        foreach (@$o->xpath('anhaenge/anhang') ?: [] as $anhang) {
            $pfad = trim((string)($anhang->daten->pfad ?? ''));
            if ($pfad === '' || !str_starts_with($pfad, 'http')) continue;

            $gruppe = strtoupper(trim((string)($anhang['gruppe'] ?? '')));
            $endung = strtolower(pathinfo((string)parse_url($pfad, PHP_URL_PATH), PATHINFO_EXTENSION));

            if ($gruppe === 'FILMLINK'
                || in_array($endung, ['mp4', 'mov', 'm4v', 'webm'], true)
                || preg_match('#(youtube\.com|youtu\.be|vimeo\.com)#i', $pfad)) {
                if ($videoUrl === '') $videoUrl = $pfad;   // das erste Video zaehlt
                continue;
            }

            if (in_array($endung, ['jpg', 'jpeg', 'png', 'webp'], true)
                || in_array($gruppe, ['TITELBILD', 'BILD'], true)) {
                $bilder[] = $pfad;
            }
        }

        // Rueckfall, falls der Anhang-Aufbau abweicht
        if (!$bilder) {
            $bilder = array_values(array_filter(
                ji_alleWerte($o, ['bilder/bild/pfad', 'bilder/bild', 'anhang/daten/pfad']),
                fn($b) => str_starts_with($b, 'http')
            ));
        }
        if ($videoUrl === '') {
            $videoUrl = ji_ersterWert($o, [
                'anhaenge/anhang[@gruppe="FILMLINK"]/daten/pfad',
                'user_defined_anyfield/ji_videos/video',
                'user_defined_anyfield/ji_video',
                'user_defined_simplefield[@feldname="video"]',
                'video', 'film',
            ]);
        }
        // Letzter Versuch: Justimmo legt Videolinks je nach Konto an
        // unterschiedlichen Stellen ab. Eine YouTube- oder Vimeo-Adresse ist
        // aber unverwechselbar — also das ganze Objekt danach absuchen.
        if ($videoUrl === '') $videoUrl = ji_videoSuche($o);

        $video = ji_video($videoUrl, $bilder[0] ?? '');

        $abgeber = ji_abgeber($o);
        $vorrang = ji_hatVorrang($o, $abgeber);

        $ergebnis[] = [
            'id'          => ji_kennung($titel, $id),
            'justimmoId'  => $id,
            'objektId'    => $objektId,
            'title'       => $titel,
            'type'        => $istMiete ? 'miete' : 'kauf',
            'price'       => ji_preisFormat($preisRoh),
            // Zahlenwerte fuer die Filterung. Aus den Anzeigetexten
            // ("€ 697.000", "95 m²") liesse sich das zwar herausrechnen,
            // waere aber vom Format der Anzeige abhaengig.
            'preisWert'   => $preisRoh !== '' ? (float)str_replace(',', '.', $preisRoh) : null,
            'flaecheWert' => is_numeric(str_replace(',', '.', $flaeche)) ? (float)str_replace(',', '.', $flaeche) : null,
            'zimmerWert'  => is_numeric(str_replace(',', '.', $zimmer)) ? (float)str_replace(',', '.', $zimmer) : null,
            'objektart'   => $objektart,
            'plz'         => $plz,
            'location'    => $lage,
            'mapQuery'    => $karte,
            'lat'         => $lat,
            'lng'         => $lng,
            // Bei Grundstuecken gibt es keine Wohnflaeche — dort ist die
            // Grundflaeche die Angabe, auf die es ankommt.
            'area'        => ji_flaecheFormat($flaeche) ?: '–',
            'grundArea'   => ji_flaecheFormat($grundflaeche) ?: '',
            'grundWert'   => is_numeric(str_replace(',', '.', $grundflaeche)) ? (float)str_replace(',', '.', $grundflaeche) : null,
            'rooms'       => ji_ganzzahl($zimmer) ?: '–',
            'baths'       => ji_ganzzahl($baeder) ?: '–',
            'abgeber'     => $abgeber,
            'vorrang'     => $vorrang,
            'gradient'    => 'linear-gradient(135deg,#2c2822,#0f0e0c)',
            'images'      => $bilder,
            'description' => array_slice($absaetze, 0, 4),
            'video'       => $video,
        ];
    }

    // Die bevorzugten Objekte nach vorne, alles andere behaelt die
    // Reihenfolge aus Justimmo. array_filter erhaelt sie, deshalb bleibt
    // die Sortierung stabil und wackelt nicht von Abruf zu Abruf.
    $vorne = array_values(array_filter($ergebnis, fn($e) => $e['vorrang']));
    $rest  = array_values(array_filter($ergebnis, fn($e) => !$e['vorrang']));

    return array_merge($vorne, $rest);
}
