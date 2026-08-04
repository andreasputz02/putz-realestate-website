<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$konfigPfad = __DIR__ . '/../justimmo-config.php';
$hatKonfig  = is_file($konfigPfad);
$konfig     = $hatKonfig ? require $konfigPfad : null;

$status = null; $fehler = null; $xmlRoh = null; $anzahlObjekte = null; $beispiel = null;

if ($hatKonfig && isset($_GET['pruefen'])) {
    $url = 'https://api.justimmo.at/rest/v1/objekt/list?' . http_build_query([
        'showDetails' => 1, 'limit' => 3, 'culture' => 'de',
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_USERPWD        => $konfig['benutzer'] . ':' . $konfig['passwort'],
    ]);
    $xmlRoh = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $fehler = curl_error($ch);
    curl_close($ch);

    if ($xmlRoh) {
        libxml_use_internal_errors(true);
        $x = simplexml_load_string($xmlRoh);
        if ($x !== false) {
            $treffer = $x->xpath('//immobilie') ?: $x->xpath('//objekt') ?: [];
            $anzahlObjekte = count($treffer);
            if ($treffer) $beispiel = $treffer[0]->asXML();
        }
    }
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
  a { color: #fbe48b; }
  .karte { background: #141416; border: 1px solid rgba(255,255,255,0.09); border-radius: 12px; padding: 20px 22px; margin-top: 18px; }
  .ok { color: #7fd18b; } .schlecht { color: #ef8080; }
  pre { background: #0d0d0f; border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 14px; overflow-x: auto; font-size: 12px; white-space: pre-wrap; word-break: break-word; }
  .btn { display: inline-block; margin-top: 14px; padding: 10px 18px; border-radius: 100px; background: #fbe48b; color: #17161a; font-weight: 700; text-decoration: none; font-size: 14px; }
  table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  td { padding: 7px 0; border-bottom: 1px solid rgba(255,255,255,0.07); font-size: 14px; vertical-align: top; }
  td:first-child { color: rgba(255,255,255,0.55); width: 210px; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Justimmo-Verbindung prüfen</h1>
  <p style="color:rgba(255,255,255,0.55);font-size:14px;">
    <a href="tippgeber.php">← zurück zur Übersicht</a> · <a href="logout.php">Abmelden</a>
  </p>

  <div class="karte">
    <h2 style="margin-top:0;">1. Zugangsdaten</h2>
    <?php if (!$hatKonfig): ?>
      <p class="schlecht">Die Datei <code>justimmo-config.php</code> fehlt noch.</p>
      <p style="margin-top:10px;font-size:14px;">
        Kopieren Sie <code>justimmo-config.example.php</code> zu <code>justimmo-config.php</code>
        und tragen Sie Benutzername und Passwort ein. Beides finden Sie in Justimmo unter
        <strong>Einstellungen → Schnittstellen → API-Export</strong>.
      </p>
    <?php else: ?>
      <p class="ok">Konfiguration gefunden.</p>
      <table>
        <tr><td>Benutzername</td><td><?php echo htmlspecialchars(substr($konfig['benutzer'], 0, 3)) . str_repeat('•', 6); ?></td></tr>
        <tr><td>Passwort</td><td><?php echo str_repeat('•', 10); ?></td></tr>
        <tr><td>Zwischenspeicher</td><td><?php echo (int)$konfig['cache_sekunden']; ?> Sekunden</td></tr>
      </table>
      <a class="btn" href="?pruefen=1">Verbindung jetzt testen</a>
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
      <tr><td>Gefundene Objekte</td><td class="<?php echo $anzahlObjekte ? 'ok' : 'schlecht'; ?>">
        <?php echo $anzahlObjekte === null ? '—' : (int)$anzahlObjekte; ?>
        <?php if ($anzahlObjekte === 0) echo ' — in Justimmo ist kein Objekt für den API-Export freigegeben'; ?>
      </td></tr>
    </table>
  </div>

  <?php if ($beispiel): ?>
  <div class="karte">
    <h2 style="margin-top:0;">3. Aufbau des ersten Objekts</h2>
    <p style="font-size:14px;color:rgba(255,255,255,0.6);">
      Anhand dieser Felder wird die Übersetzung auf die Website feinjustiert.
    </p>
    <pre><?php echo htmlspecialchars(substr($beispiel, 0, 6000)); ?></pre>
  </div>
  <?php elseif ($xmlRoh): ?>
  <div class="karte">
    <h2 style="margin-top:0;">3. Rohantwort</h2>
    <pre><?php echo htmlspecialchars(substr($xmlRoh, 0, 3000)); ?></pre>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>
