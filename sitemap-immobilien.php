<?php
// ============================================================
//  Sitemap der Objektseiten: /sitemap-immobilien.xml
//
//  Die feste sitemap.xml kennt nur die gebauten Seiten. Die Objekte
//  wechseln laufend, deshalb entsteht diese Liste bei jedem Aufruf aus
//  denselben Daten wie die Objektseiten selbst (objekte-lib.php).
//  robots.txt verweist auf beide.
// ============================================================

require_once __DIR__ . '/objekte-lib.php';

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

// Stand der Objektdaten: Aenderungsdatum des Justimmo-Speichers
$stand = is_file(JI_CACHE_DATEI) ? date('Y-m-d', filemtime(JI_CACHE_DATEI)) : date('Y-m-d');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach (obj_alle() as $o) {
    if (empty($o['id'])) continue;
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars(obj_url($o), ENT_XML1, 'UTF-8') . "</loc>\n";
    echo "    <lastmod>$stand</lastmod>\n";
    echo '    <changefreq>' . (!empty($o['verkauft']) ? 'yearly' : 'weekly') . "</changefreq>\n";
    echo "    <priority>0.6</priority>\n";
    echo "  </url>\n";
}
echo "</urlset>\n";
