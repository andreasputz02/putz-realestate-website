<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../justimmo-lib.php';

$konfigPfad = __DIR__ . '/../justimmo-config.php';
$hatKonfig  = is_file($konfigPfad);
$konfig     = $hatKonfig ? require $konfigPfad : null;

$status = null; $fehler = null; $xmlRoh = null;
$gemeldet = null; $anzahlKnoten = null; $beispielXml = null; $objekte = []; $videoSpuren = [];

if ($hatKonfig && isset($_GET['pruefen'])) {
    // Cache umgehen: hier soll immer der aktuelle Stand geprueft werden.
    $xmlRoh = ji_abrufen('objekt/list', [
        'showDetails' => 1,
        'limit'       => 5,
        'culture'     => 'de',
        'picturesize' => 'big',
    ], $konfig, $status, $fehler);

    if ($xmlRoh) {
        libxml_use_internal_errors(true);
        $x = simplexml_load_string($xmlRoh);
        if ($x !== false) {
            $c = $x->xpath('//query-result/count');
            if ($c) $gemeldet = (int)(string)$c[0];

            $knoten = ji_objektKnoten($x);
            $anzahlKnoten = count($knoten);
            if ($knoten) $beispielXml = $knoten[0]->asXML();

            $objekte = ji_umwandeln($xmlRoh);
        }
    }

    // Kommt in der Antwort ueberhaupt irgendwo ein Video vor?
    if ($xmlRoh && preg_match_all('#.{0,130}(?:youtube|youtu\.be|vimeo|FILMLINK|\.mp4|\.mov).{0,130}#i', $xmlRoh, $mm)) {
        $videoSpuren = array_slice($mm[0], 0, 12);
    }

    // Zwischenspeicher leeren, damit die Website sofort den neuen Stand zieht.
    if (is_file(JI_CACHE_DATEI)) @unlink(JI_CACHE_DATEI);
}

function feld($wert, $leerOk = false) {
    $leer = ($wert === '' || $wert === null || $wert === '–' || $wert === []);
    $klasse = $leer && !$leerOk ? 'schlecht' : 'ok';
    if (is_array($wert)) $wert = count($wert) . ' Stück';
    if ($leer) $wert = '— leer —';
    return '<span class="' . $klasse . '">' . htmlspecialchars((string)$wert) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Justimmo-Verbindung prüfen — PUTZ Real Estate</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, Arial, sans-serif; background: #0b0b0c; color: #fff; padding: 40px 24px; line-height: 1.6; }
  .wrap { max-width: 900px; margin: 0 auto; }
  h1 { font-size: 24px; font-weight: 600; margin-bottom: 6px; }
  h2 { font-size: 16px; margin: 28px 0 10px; color: #fbe48b; }
  h3 { font-size: 14px; margin: 18px 0 6px; color: rgba(255,255,255,0.85); }
  a { color: #fbe48b; }
  .karte { background: #141416; border: 1px solid rgba(255,255,255,0.09); border-radius: 12px; padding: 20px 22px; margin-top: 18px; }
  .ok { color: #7fd18b; } .schlecht { color: #ef8080; }
  .hinweis { font-size: 13.5px; color: rgba(255,255,255,0.55); }
  pre { background: #0d0d0f; border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 14px; overflow-x: auto; font-size: 12px; white-space: pre-wrap; word-break: break-word; max-height: 480px; }
  .btn { display: inline-block; margin-top: 14px; padding: 10px 18px; border-radius: 100px; background: #fbe48b; color: #17161a; font-weight: 700; text-decoration: none; font-size: 14px; }
  table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  td { padding: 7px 0; border-bottom: 1px solid rgba(255,255,255,0.07); font-size: 14px; vertical-align: top; }
  td:first-child { color: rgba(255,255,255,0.55); width: 210px; }
  .objekt { border-top: 1px solid rgba(255,255,255,0.12); margin-top: 22px; padding-top: 8px; }
  .objekt:first-of-type { border-top: 0; margin-top: 0; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Justimmo-Verbindung prüfen</h1>
  <p class="hinweis"><a href="tippgeber.php">← zurück zur Übersicht</a> · <a href="logout.php">Abmelden</a></p>

  <div class="karte">
    <h2 style="margin-top:0;">1. Zugangsdaten</h2>
    <?php if (!$hatKonfig): ?>
      <p class="schlecht">Die Datei <code>justimmo-config.php</code> fehlt noch.</p>
      <p class="hinweis" style="margin-top:10px;">
        Kopieren Sie <code>justimmo-config.example.php</code> zu <code>justimmo-config.php</code>
        und tragen Sie Benutzername und Passwort ein. Beides finden Sie in Justimmo unter
        <strong>Einstellungen → Schnittstellen → API-Export</strong>.
      </p>
    <?php else: ?>
      <p class="ok">Konfiguration gefunden.</p>
      <table>
        <tr><td>Benutzername</td><td><?php echo htmlspecialchars(substr((string)$konfig['benutzer'], 0, 3)) . str_repeat('•', 6); ?></td></tr>
        <tr><td>Passwort</td><td><?php echo str_repeat('•', 10); ?></td></tr>
        <tr><td>Zwischenspeicher</td><td><?php echo (int)$konfig['cache_sekunden']; ?> Sekunden</td></tr>
      </table>
      <a class="btn" href="?pruefen=1">Verbindung jetzt testen</a>
      <p class="hinweis" style="margin-top:10px;">Der Test leert dabei den Zwischenspeicher — die Website zeigt danach sofort den aktuellen Stand.</p>
    <?php endif; ?>
  </div>

  <?php if (isset($_GET['pruefen'])): ?>
  <div class="karte">
    <h2 style="margin-top:0;">2. Antwort von Justimmo</h2>
    <table>
      <tr><td>HTTP-Status</td><td class="<?php echo $status === 200 ? 'ok' : 'schlecht'; ?>">
        <?php echo (int)$status; ?>
        <?php if ($status === 401) echo ' — Zugangsdaten stimmen nicht'; ?>
        <?php if ($status === 200) echo ' — Verbindung steht'; ?>
      </td></tr>
      <?php if ($fehler): ?><tr><td>Verbindungsfehler</td><td class="schlecht"><?php echo htmlspecialchars($fehler); ?></td></tr><?php endif; ?>
      <?php if ($gemeldet !== null): ?>
      <tr><td>Von Justimmo gemeldet</td><td class="<?php echo $gemeldet ? 'ok' : 'schlecht'; ?>">
        <?php echo $gemeldet; ?> Objekt<?php echo $gemeldet === 1 ? '' : 'e'; ?>
        <?php if ($gemeldet === 0) echo ' — in Justimmo ist kein Objekt für den API-Export freigegeben'; ?>
      </td></tr>
      <?php endif; ?>
      <tr><td>Davon eingelesen</td><td class="<?php echo $anzahlKnoten ? 'ok' : 'schlecht'; ?>">
        <?php echo $anzahlKnoten === null ? '—' : (int)$anzahlKnoten; ?>
        <?php if ($gemeldet > 0 && !$anzahlKnoten) echo ' — Aufbau unbekannt, bitte die Rohantwort unten schicken'; ?>
      </td></tr>
    </table>
  </div>

  <?php if ($objekte): ?>
  <div class="karte">
    <h2 style="margin-top:0;">3. So erscheint es auf der Website</h2>
    <p class="hinweis">Rot heißt: das Feld konnte nicht zugeordnet werden.</p>
    <?php foreach ($objekte as $ob): ?>
      <div class="objekt">
        <table>
          <tr><td>Titel</td><td><?php echo feld($ob['title']); ?></td></tr>
          <tr><td>Art</td><td><?php echo feld($ob['type']); ?></td></tr>
          <tr><td>Preis</td><td><?php echo feld($ob['price']); ?></td></tr>
          <tr><td>Ort (Anzeige)</td><td><?php echo feld($ob['location']); ?></td></tr>
          <tr><td>Ort (Karte)</td><td><?php echo feld($ob['mapQuery']); ?></td></tr>
          <tr><td>Fläche</td><td><?php echo feld($ob['area']); ?></td></tr>
          <tr><td>Zimmer</td><td><?php echo feld($ob['rooms']); ?></td></tr>
          <tr><td>Bäder</td><td><?php echo feld($ob['baths']); ?></td></tr>
          <tr><td>Bilder</td><td><?php echo feld($ob['images']); ?></td></tr>
          <tr><td>Video</td><td><?php
            if (!$ob['video']) {
                echo '<span class="schlecht">— keines —</span>';
            } elseif (!empty($ob['video']['einbettung'])) {
                echo '<span class="ok">Link</span> <span class="hinweis">' . htmlspecialchars($ob['video']['einbettung']) . '</span>';
            } else {
                echo '<span class="ok">Datei</span> <span class="hinweis">' . htmlspecialchars($ob['video']['src']) . '</span>';
            }
          ?></td></tr>
          <tr><td>Beschreibung</td><td><?php echo feld($ob['description']); ?> Absätze</td></tr>
          <tr><td>Justimmo-Nummer</td><td><?php echo feld($ob['justimmoId']); ?></td></tr>
          <tr><td>Interne Objektnummer</td><td>
            <?php echo feld($ob['objektId']); ?>
            <?php if (empty($ob['objektId'])): ?>
              <span class="hinweis">— ohne diese Nummer landet eine Anfrage nur per E-Mail bei Ihnen, nicht in Justimmo</span>
            <?php endif; ?>
          </td></tr>
          <tr><td>Adresse auf der Seite</td><td class="hinweis">immobilie.html?id=<?php echo htmlspecialchars($ob['id']); ?></td></tr>
        </table>
        <?php if ($ob['images']): ?>
          <h3>Erstes Bild</h3>
          <img src="<?php echo htmlspecialchars($ob['images'][0]); ?>" alt="" style="max-width:100%;border-radius:8px;">
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($xmlRoh): ?>
  <div class="karte">
    <h2 style="margin-top:0;">4. Videosuche</h2>
    <?php if ($videoSpuren): ?>
      <p class="hinweis">In der Antwort gefunden — so liefert Justimmo das Video aus:</p>
      <pre><?php echo htmlspecialchars(implode("\n\n", $videoSpuren)); ?></pre>
    <?php else: ?>
      <p class="schlecht">In der gesamten Antwort kommt kein Video vor.</p>
      <p class="hinweis">Justimmo gibt den Videolink also nicht über die Schnittstelle heraus —
      unabhängig davon, was im Objekt hinterlegt ist.</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($beispielXml): ?>
  <div class="karte">
    <h2 style="margin-top:0;">5. Rohdaten des ersten Objekts</h2>
    <p class="hinweis">Nur zur Fehlersuche — falls oben ein Feld rot ist.</p>
    <pre><?php echo htmlspecialchars(substr($beispielXml, 0, 20000)); ?></pre>
  </div>
  <?php elseif ($xmlRoh): ?>
  <div class="karte">
    <h2 style="margin-top:0;">5. Rohantwort</h2>
    <pre><?php echo htmlspecialchars(substr($xmlRoh, 0, 5000)); ?></pre>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>
