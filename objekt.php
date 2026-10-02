<?php
// ============================================================
//  Objektseite mit eigener Adresse: /immobilie/<kennung>
//
//  Frueher gab es nur /immobilie?id=..., und das Objekt entstand erst im
//  Browser — aus justimmo.php, das robots.txt fuer Suchmaschinen sperrt.
//  Google sah deshalb fuer alle Objekte dieselbe leere Seite.
//
//  Jetzt liefert der Server jede Objektseite fertig aus: eigener Titel,
//  eigene Beschreibung, Canonical, Vorschau beim Teilen, Objektdaten fuer
//  Google, Text, Eckdaten und Bilder im HTML. Grundlage ist immobilie.html;
//  listings.js baut im Browser wie bisher Galerie, Karte und Video auf.
//
//  Die .htaccess leitet /immobilie/<kennung> hierher (?slug=...).
// ============================================================

require_once __DIR__ . '/objekte-lib.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

$slug = (string)($_GET['slug'] ?? '');
$o = preg_match('/^[a-z0-9-]{1,200}$/', $slug) ? obj_finden($slug) : null;

if (!$o) {
    http_response_code(404);
    readfile(__DIR__ . '/404.html');
    exit;
}

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

$titel    = trim((string)($o['title'] ?? 'Immobilie'));
$lage     = trim((string)($o['location'] ?? ''));
$verkauft = !empty($o['verkauft']);
$istMiete = ($o['type'] ?? '') === 'miete';
$istGrund = preg_match('/grundst/i', ($o['objektart'] ?? '') . ' ' . $titel) === 1;
$flaeche  = $istGrund && !empty($o['grundArea']) ? $o['grundArea'] : ($o['area'] ?? '–');
$flaechenName = $istGrund ? 'Grundfläche' : 'Wohnfläche';
$bilder   = array_values(array_filter((array)($o['images'] ?? []), 'is_string'));
$url      = obj_url($o);

// Beschreibung: Justimmo liefert bereits bereinigtes HTML (siehe
// justimmo-lib.php), handgepflegte Objekte reinen Text.
$absaetze = [];
foreach ((array)($o['description'] ?? []) as $t) {
    $t = (string)$t;
    $absaetze[] = str_contains($t, '<') ? $t : '<p>' . $e($t) . '</p>';
}
$beschreibungHtml = implode("\n", $absaetze);

// Meta-Beschreibung: erster Satz bzw. erste ~150 Zeichen, an einer
// Wortgrenze gekuerzt. Vorn steht, was es ist und wo.
$klartext = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(implode(' ', (array)($o['description'] ?? []))), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
$kopf = trim(($o['objektart'] ?? '') . ($lage !== '' ? ' in ' . $lage : ''));
$meta = ($kopf !== '' ? $kopf . ': ' : '') . $klartext;
if (mb_strlen($meta) > 155) {
    $meta = mb_substr($meta, 0, 154);
    $meta = preg_replace('/\s+\S*$/u', '', $meta) . '…';
}

$seitentitel = $titel . ($verkauft ? ' (verkauft)' : '') . ' | PUTZ Real Estate';
$bildAbs = $bilder ? obj_absolut($bilder[0]) : OBJ_BASIS . 'assets/img/vorschau/standard.jpg';

$html = file_get_contents(__DIR__ . '/immobilie.html');

/** Ersetzt den Inhalt des ersten Elements mit data-field="$feld". */
$fuelle = function (string $feld, string $inhalt) use (&$html) {
    $html = preg_replace_callback(
        '~(<(\w+)\b[^>]*\bdata-field="' . preg_quote($feld, '~') . '"[^>]*>)(.*?)(</\2>)~s',
        fn($m) => $m[1] . $inhalt . $m[4],
        $html, 1
    );
};
$beschrifte = function (string $name, string $text) use (&$html) {
    $html = preg_replace_callback('~(data-label="' . preg_quote($name, '~') . '"[^>]*>)[^<]*~',
        fn($m) => $m[1] . $text, $html, 1);
};
$ersetze = function (string $alt, string $neu) use (&$html) {
    $html = str_replace($alt, $neu, $html);
};

// ---------- Kopf ----------
// Die Seite liegt eine Ebene tiefer (/immobilie/...). <base> laesst alle
// relativen Pfade (css/, js/, assets/, send-mail.php ...) weiter vom
// Hauptverzeichnis aus aufloesen, wie auf jeder anderen Seite.
$ersetze('<meta charset="UTF-8">', "<meta charset=\"UTF-8\">\n<base href=\"/\">");
// Ersatz immer per Rueckruf: ein "$1" oder "\\" in einem Objekttitel darf
// nicht als Verweis gedeutet werden.
$setze = function (string $muster, string $neu) use (&$html) {
    $html = preg_replace_callback($muster, fn() => $neu, $html, 1);
};
$setze('~<title>.*?</title>~s', '<title>' . $e($seitentitel) . '</title>');
$setze('~<meta name="description" content="[^"]*">~', '<meta name="description" content="' . $e($meta) . '">');
$setze('~<link rel="canonical" href="[^"]*">~', '<link rel="canonical" href="' . $e($url) . '">');
$setze('~<meta property="og:title" content="[^"]*">~', '<meta property="og:title" content="' . $e($seitentitel) . '">');
$setze('~<meta property="og:description" content="[^"]*">~', '<meta property="og:description" content="' . $e($meta) . '">');
$setze('~<meta property="og:url" content="[^"]*">~', '<meta property="og:url" content="' . $e($url) . '">');
$setze('~<meta property="og:image" content="[^"]*">~', '<meta property="og:image" content="' . $e($bildAbs) . '">');
$setze('~<meta property="og:image:alt" content="[^"]*">~', '<meta property="og:image:alt" content="' . $e($titel) . '">');
if ($bilder) {   // Masse der Objektfotos sind unbekannt
    $html = preg_replace('~\n<meta property="og:image:(width|height)" content="[^"]*">~', '', $html);
}

// Brotkrumen-Daten: dritte Stufe ist das Objekt selbst
$brot = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Start', 'item' => OBJ_BASIS],
    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Unsere Immobilien', 'item' => OBJ_BASIS . 'immobilien'],
    ['@type' => 'ListItem', 'position' => 3, 'name' => $titel, 'item' => $url],
]];
$json = fn($d) => json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG);
$html = preg_replace_callback('~(<script type="application/ld\+json" id="brotkrumen">\n).*?(\n</script>)~s',
    fn($m) => $m[1] . $json($brot) . $m[2], $html, 1);

// Objektdaten fuer Google (schema.org RealEstateListing)
$art = strtolower((string)($o['objektart'] ?? ''));
$typ = str_contains($art, 'wohnung') ? 'Apartment' : (str_contains($art, 'haus') ? 'House' : 'Place');
$ortName = trim(preg_replace('/^\d{4}\s*/', '', explode(',', $lage)[0] ?? ''));
$gegenstand = array_filter([
    '@type'   => $typ,
    'name'    => $titel,
    'address' => array_filter(['@type' => 'PostalAddress', 'postalCode' => $o['plz'] ?? '',
                               'addressLocality' => $ortName, 'addressCountry' => 'AT']),
    'floorSize' => (!$istGrund && !empty($o['flaecheWert'])) ? ['@type' => 'QuantitativeValue', 'value' => $o['flaecheWert'], 'unitCode' => 'MTK'] : null,
    'numberOfRooms' => !empty($o['zimmerWert']) ? $o['zimmerWert'] : null,
]);
if (!empty($o['lat']) && !empty($o['lng'])) {
    $gegenstand['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => $o['lat'], 'longitude' => $o['lng']];
}
$angebot = array_filter([
    '@type' => 'Offer',
    'price' => !empty($o['preisWert']) ? $o['preisWert'] : null,
    'priceCurrency' => !empty($o['preisWert']) ? 'EUR' : null,
    'businessFunction' => $istMiete ? 'http://purl.org/goodrelations/v1#LeaseOut' : 'http://purl.org/goodrelations/v1#Sell',
    'availability' => $verkauft ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock',
    'offeredBy' => ['@id' => OBJ_BASIS . '#unternehmen'],
]);
$inserat = array_filter([
    '@context' => 'https://schema.org',
    '@type' => 'RealEstateListing',
    'name' => $titel,
    'url' => $url,
    'description' => $meta,
    'image' => array_map('obj_absolut', array_slice($bilder, 0, 6)),
    'about' => $gegenstand,
    'offers' => $angebot,
    'provider' => ['@id' => OBJ_BASIS . '#unternehmen'],
]);
$ersetze('</head>', "<script type=\"application/ld+json\">\n" . $json($inserat) . "\n</script>\n</head>");

// ---------- Inhalt ----------
$fuelle('type', $verkauft ? 'Erfolgreich verkauft' : ($istMiete ? 'Miete' : 'Kauf'));
$fuelle('title', $e($titel));
$fuelle('title-crumb', $e($titel));
$fuelle('location', $e($lage));
$fuelle('hero-price', $e($o['price'] ?? '–'));
$fuelle('hero-area', $e($flaeche));
$beschrifte('hero-area', $flaechenName);
$fuelle('hero-rooms', $e($o['rooms'] ?? '–'));
$fuelle('area', $e($flaeche));
$beschrifte('area', $flaechenName);
$fuelle('rooms', $e($o['rooms'] ?? '–'));
$fuelle('baths', $e($o['baths'] ?? '–'));
$fuelle('baths-label', ($o['baths'] ?? '') === '1' ? 'Bad' : 'Bäder');
$fuelle('price', $e($o['price'] ?? '–'));
$fuelle('description', $beschreibungHtml);
if ($bilder) {
    // Fotos schon im HTML, damit Suchmaschinen und Besucher ohne Skript sie
    // sehen. listings.js ersetzt das durch die Galerie mit Vergroesserung.
    $fotos = '';
    foreach (array_slice($bilder, 0, 8) as $i => $b) {
        $fotos .= '<img src="' . $e($b) . '" alt="' . $e($titel . ' – Foto ' . ($i + 1)) . '"' . ($i ? ' loading="lazy"' : '') . '>';
    }
    $fuelle('gallery', $fotos);
}

// Weg zur passenden Ortsseite — Objekt und Ort stuetzen sich gegenseitig.
$ortsseite = obj_ortsseite($o);
if ($ortsseite) {
    $ersetze('<div class="property-description" data-field="description">',
        '<p class="objekt-ort-link"><a href="' . $e($ortsseite[0]) . '">Immobilienmarkt, Preise und verkaufte Objekte: '
        . $e($ortsseite[1]) . ' →</a></p>' . "\n" . '<div class="property-description" data-field="description">');
}

echo $html;
