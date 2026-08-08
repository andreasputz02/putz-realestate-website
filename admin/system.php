<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$wurzel = dirname(__DIR__);

/**
 * Prueft eine PHP-Datei auf Syntaxfehler, OHNE sie auszufuehren.
 *
 * token_get_all() mit TOKEN_PARSE laesst den Parser ueber die Datei
 * laufen und wirft bei ungueltigem Code einen ParseError. Ein blosses
 * include wuerde die Datei dagegen ausfuehren — hier undenkbar,
 * send-mail.php verschickt E-Mails.
 */
function syntax_pruefen(string $pfad): array {
    if (!is_file($pfad)) return ['fehlt', 'Datei nicht gefunden'];
    try {
        token_get_all(file_get_contents($pfad), TOKEN_PARSE);
        return ['ok', 'Syntax in Ordnung'];
    } catch (ParseError $e) {
        return ['fehler', $e->getMessage() . ' (Zeile ' . $e->getLine() . ')'];
    } catch (Throwable $e) {
        return ['fehler', $e->getMessage()];
    }
}

$dateien = [
    'send-mail.php', 'justimmo.php', 'justimmo-lib.php',
    'ref-token.php', 'tippgeber-db.php', 'tippgeber-login.php', 'tippgeber-app.php',
    'admin/empfehlungen.php',
];
$pruefungen = [];
foreach ($dateien as $d) {
    $pruefungen[$d] = syntax_pruefen($wurzel . '/' . $d);
}

$pdoTreiber = class_exists('PDO') ? PDO::getAvailableDrivers() : [];
$hatSqlite  = in_array('sqlite', $pdoTreiber, true);

$datenOrdner = $wurzel . '/data';
$schreibbar  = is_dir($datenOrdner) && is_writable($datenOrdner);

$listen = [];
foreach (['tippgeber.json', 'suchkunden.json', 'empfehlungen.json'] as $l) {
    $p = $datenOrdner . '/' . $l;
    $listen[$l] = is_file($p) ? count(json_decode(file_get_contents($p), true) ?: []) : null;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Systemprüfung — PUTZ Real Estate</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, Arial, sans-serif; background: #0b0b0c; color: #fff; padding: 40px 24px; line-height: 1.6; }
  .wrap { max-width: 880px; margin: 0 auto; }
  h1 { font-size: 24px; font-weight: 600; margin-bottom: 6px; }
  h2 { font-size: 16px; margin: 0 0 12px; color: #fbe48b; }
  a { color: #fbe48b; }
  .karte { background: #141416; border: 1px solid rgba(255,255,255,0.09); border-radius: 12px; padding: 20px 22px; margin-top: 18px; }
  .ok { color: #7fd18b; } .schlecht { color: #ef8080; } .warn { color: #f0c674; }
  .hinweis { font-size: 13px; color: rgba(255,255,255,0.55); }
  table { width: 100%; border-collapse: collapse; }
  td { padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,0.07); font-size: 14px; vertical-align: top; }
  td:first-child { color: rgba(255,255,255,0.55); width: 230px; }
  code { background: rgba(255,255,255,0.08); padding: 1px 5px; border-radius: 4px; font-size: 13px; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Systemprüfung</h1>
  <p class="hinweis"><a href="tippgeber.php">← zurück zur Übersicht</a> · <a href="empfehlungen.php">Empfehlungen</a> · <a href="justimmo-test.php">Justimmo prüfen</a> · <a href="logout.php">Abmelden</a></p>

  <div class="karte">
    <h2>1. Syntax der PHP-Dateien</h2>
    <p class="hinweis" style="margin-bottom:10px;">Geprüft wird der Code, ohne ihn auszuführen.</p>
    <table>
      <?php foreach ($pruefungen as $datei => [$stand, $text]): ?>
      <tr>
        <td><code><?php echo htmlspecialchars($datei); ?></code></td>
        <td class="<?php echo $stand === 'ok' ? 'ok' : ($stand === 'fehlt' ? 'warn' : 'schlecht'); ?>">
          <?php echo htmlspecialchars($text); ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="karte">
    <h2>2. Datenbank</h2>
    <table>
      <tr><td>PHP-Version</td><td><?php echo htmlspecialchars(PHP_VERSION); ?></td></tr>
      <tr><td>PDO-Treiber</td><td><?php echo $pdoTreiber ? htmlspecialchars(implode(', ', $pdoTreiber)) : '<span class="schlecht">keine</span>'; ?></td></tr>
      <tr><td>SQLite nutzbar</td>
        <td class="<?php echo $hatSqlite ? 'ok' : 'schlecht'; ?>">
          <?php echo $hatSqlite ? 'ja — die Tippgeber-App kann darauf aufbauen' : 'nein — es bleibt bei den JSON-Dateien'; ?>
        </td></tr>
      <tr><td>Ordner <code>data/</code></td>
        <td class="<?php echo $schreibbar ? 'ok' : 'schlecht'; ?>">
          <?php echo $schreibbar ? 'beschreibbar' : 'nicht beschreibbar — hier kann nichts gespeichert werden'; ?>
        </td></tr>
    </table>
  </div>

  <div class="karte">
    <h2>3. Gespeicherte Datensätze</h2>
    <table>
      <?php foreach ($listen as $name => $anzahl): ?>
      <tr>
        <td><code><?php echo htmlspecialchars($name); ?></code></td>
        <td class="<?php echo $anzahl === null ? 'hinweis' : 'ok'; ?>">
          <?php echo $anzahl === null ? 'noch nicht angelegt' : $anzahl . ' Einträge'; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <p class="hinweis" style="margin-top:12px;">
      <code>empfehlungen.json</code> entsteht, sobald jemand über einen Tippgeber-Link
      ein Formular abschickt. Vorher ist sie erwartungsgemäß nicht vorhanden.
    </p>
  </div>
</div>
</body>
</html>
