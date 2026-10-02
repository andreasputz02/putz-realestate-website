<?php
// ============================================================
//  Ortsseiten mit aktuellen Objekten
//
//  Die Ortsseiten (immobilien-verkaufen-*.html) bleiben fertige HTML-
//  Dateien. Beim Ausliefern setzt diese Datei an der Marke
//  <!-- ===== Aktuelle Objekte ===== --> die aktuellen Objekte der Gegend
//  ein — aus denselben Daten wie die Objektseiten (objekte-lib.php), also
//  immer mit dem Stand aus Justimmo, und fuer Suchmaschinen im HTML.
//  Gibt es in der Gegend gerade nichts, faellt der Abschnitt weg.
//
//  Die .htaccess leitet /immobilien-verkaufen-<ort> hierher (?s=...).
// ============================================================

require_once __DIR__ . '/objekte-lib.php';

$s = (string)($_GET['s'] ?? '');
$datei = __DIR__ . '/' . $s . '.html';
if (!preg_match('/^immobilien-verkaufen-[a-z0-9-]+$/', $s) || !is_file($datei)) {
    http_response_code(404);
    readfile(__DIR__ . '/404.html');
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

$html = file_get_contents($datei);
$MARKE = '<!-- ===== Aktuelle Objekte ===== -->';
if (!str_contains($html, $MARKE)) { echo $html; exit; }

$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$seite = substr($s, strlen('immobilien-verkaufen-'));
$objekte = obj_fuerOrt($seite, 6);

$abschnitt = '';
if ($objekte) {
    // "wo" steht als data-Attribut an der Marke ("in Mistelbach", "am Alsergrund")
    $wo = preg_match('~<!-- ===== Aktuelle Objekte ===== --><!-- wo:(.*?) -->~', $html, $m) ? $m[1] : '';
    $karten = '';
    foreach ($objekte as $o) {
        $bilder = array_values(array_filter((array)($o['images'] ?? []), 'is_string'));
        $deck = $bilder
            ? '<div class="scene has-photo" style="background-image:url(\'' . $e($bilder[0]) . '\')"></div>'
            : '<div class="scene" style="background:' . $e($o['gradient'] ?? '#1a1a1a') . '"></div>';
        $istGrund = preg_match('/grundst/i', ($o['objektart'] ?? '') . ' ' . ($o['title'] ?? '')) === 1;
        $felder = $istGrund ? [[$o['grundArea'] ?? '', 'Grundfläche']]
                            : [[$o['area'] ?? '', 'Wohnfläche'], [$o['rooms'] ?? '', 'Zimmer']];
        $masse = '';
        foreach ($felder as [$w, $n]) {
            if ($w !== '' && $w !== '–') $masse .= '<div><strong>' . $e($w) . '</strong><span>' . $n . '</span></div>';
        }
        if (!empty($o['justimmoId'])) $masse .= '<div><strong>' . $e($o['justimmoId']) . '</strong><span>Objektnr.</span></div>';
        $karten .= '
        <a class="listing-card" data-reveal href="immobilie/' . $e($o['id']) . '">
          <div class="listing-media">
            ' . $deck . '
            <span class="tag">' . (($o['type'] ?? '') === 'miete' ? 'Miete' : 'Kauf') . '</span>
            <span class="price-tag">' . $e($o['price'] ?? '') . '</span>
          </div>
          <div class="listing-body">
            <h3>' . $e($o['title'] ?? '') . '</h3>
            <div class="loc">' . $e($o['location'] ?? '') . '</div>
            <div class="listing-specs">' . $masse . '</div>
          </div>
        </a>';
    }
    $abschnitt = '
  <section class="section section-dark ort-objekte" style="padding-top:0;">
    <div class="container">
      <div class="section-head" data-reveal>
        <div>
          <h2 class="h2-breit">Aktuell ' . $e($wo) . ' zu haben</h2>
        </div>
      </div>
      <div class="listing-grid">' . $karten . '
      </div>
      <p class="regionen-mehr" data-reveal><a href="immobilien">Alle Immobilien ansehen <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a></p>
    </div>
  </section>
';
}

$html = preg_replace_callback('~<!-- ===== Aktuelle Objekte ===== -->(<!-- wo:.*? -->)?~',
    fn() => $abschnitt, $html, 1);
echo $html;
