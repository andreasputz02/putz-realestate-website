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
    'send-mail.php', 'justimmo.php', 'justimmo-lib.php', 'mail-versand.php',
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

// Stand der Datenbank, auf der die Tippgeber-App arbeitet.
$dbStand = null;
$dbFehler = '';
$adressen = [];
$linkListe = [];
try {
    require_once $wurzel . '/tippgeber-db.php';
    $db = tg_db();
    $dbStand = [];
    foreach (['tippgeber', 'empfehlungen', 'anmeldelinks'] as $tabelle) {
        $dbStand[$tabelle] = (int)$db->query("SELECT COUNT(*) FROM $tabelle")->fetchColumn();
    }
    $dbGroesse = is_file(TG_DB_DATEI) ? filesize(TG_DB_DATEI) : 0;

    // Genau diese Adressen funktionieren bei der Anmeldung — jede andere
    // Schreibweise fuehrt zur immer gleichen, nichtssagenden Antwort.
    $adressen = $db->query("SELECT vorname, nachname, email FROM tippgeber ORDER BY id")->fetchAll();

    // Wurde ueberhaupt ein Anmeldelink erzeugt? Wenn ja, lag es nicht an
    // der Adresse, sondern am Mailversand.
    $linkListe = $db->query("
        SELECT l.erstellt, l.gueltig_bis, l.benutzt, t.email
        FROM anmeldelinks l JOIN tippgeber t ON t.id = l.tippgeber_id
        ORDER BY l.erstellt DESC LIMIT 5
    ")->fetchAll();
} catch (Throwable $e) {
    $dbFehler = $e->getMessage();
}

// Testnachricht verschicken, um den Mailversand als Ursache auszuschliessen.
$testErgebnis = null;
if (!empty($_POST['testmail'])) {
    $ziel = trim($_POST['testmail']);
    if (filter_var($ziel, FILTER_VALIDATE_EMAIL)) {
        // Bewusst ueber denselben Weg wie alle echten Nachrichten —
        // sonst prueft der Test nicht das, was spaeter tatsaechlich laeuft.
        require_once $wurzel . '/mail-versand.php';
        $ok = nachricht_senden(
            $ziel,
            'Testnachricht vom Server',
            "Diese Nachricht bestätigt, dass der Mailversand vom Server funktioniert.\n\n"
            . "Sie wurde über denselben Weg verschickt wie der Anmeldelink für den\n"
            . "Tippgeber-Bereich — mit Umschlag-Absender, Message-ID und Date.\n\n"
            . "PUTZ Real Estate"
        );
        $testErgebnis = [$ok, $ziel];
    } else {
        $testErgebnis = [null, $ziel];
    }
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

  <div class="karte">
    <h2>4. Datenbank der Tippgeber-App</h2>
    <?php if ($dbFehler !== ''): ?>
      <p class="schlecht">Die Datenbank ist nicht erreichbar: <?php echo htmlspecialchars($dbFehler); ?></p>
    <?php else: ?>
      <table>
        <tr><td>Datei</td><td class="ok"><?php echo number_format($dbGroesse / 1024, 1, ',', '.'); ?> KB · von außen gesperrt</td></tr>
        <tr><td>Tippgeber</td>
          <td class="<?php echo $dbStand['tippgeber'] ? 'ok' : 'warn'; ?>">
            <?php echo $dbStand['tippgeber']; ?>
            <?php if ($dbStand['tippgeber'] === (int)($listen['tippgeber.json'] ?? -1)): ?>
              — deckt sich mit der bisherigen Liste, Übernahme hat geklappt
            <?php endif; ?>
          </td></tr>
        <tr><td>Empfehlungen</td><td class="<?php echo $dbStand['empfehlungen'] ? 'ok' : 'hinweis'; ?>"><?php echo $dbStand['empfehlungen']; ?></td></tr>
        <tr><td>Offene Anmeldelinks</td><td class="hinweis"><?php echo $dbStand['anmeldelinks']; ?> — verfallen nach 30 Minuten</td></tr>
      </table>
    <?php endif; ?>
  </div>

  <div class="karte">
    <h2>5. Anmeldung — Fehlersuche</h2>

    <p class="hinweis" style="margin-bottom:10px;">
      <strong>Nur diese Adressen funktionieren.</strong> Jede andere Schreibweise führt zur
      immer gleichen Antwort, ohne dass eine Mail rausgeht — das ist Absicht, damit man nicht
      ausprobieren kann, wer Tippgeber ist.
    </p>
    <table>
      <?php foreach ($adressen as $a): ?>
        <tr>
          <td><?php echo htmlspecialchars(trim($a['vorname'] . ' ' . $a['nachname'])); ?></td>
          <td><code><?php echo htmlspecialchars($a['email']); ?></code></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$adressen): ?>
        <tr><td colspan="2" class="schlecht">Keine Tippgeber in der Datenbank — die Übernahme hat nicht geklappt.</td></tr>
      <?php endif; ?>
    </table>

    <h3 style="font-size:14px; margin:22px 0 8px; color:rgba(255,255,255,0.8);">Zuletzt erzeugte Anmeldelinks</h3>
    <?php if ($linkListe): ?>
      <p class="hinweis" style="margin-bottom:8px;">
        Steht hier ein Eintrag, wurde der Link erzeugt — dann lag es nicht an der Adresse,
        sondern am Mailversand oder am Spam-Ordner.
      </p>
      <table>
        <?php foreach ($linkListe as $l): ?>
          <tr>
            <td><?php echo htmlspecialchars($l['erstellt']); ?></td>
            <td><?php echo htmlspecialchars($l['email']); ?>
              — <?php echo (int)$l['benutzt'] === 1 ? 'verwendet' : 'noch offen'; ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php else: ?>
      <p class="warn">Es wurde noch nie ein Anmeldelink erzeugt. Die eingegebene Adresse
      stand also nicht in der Liste oben.</p>
    <?php endif; ?>

    <h3 style="font-size:14px; margin:22px 0 8px; color:rgba(255,255,255,0.8);">Testnachricht schicken</h3>
    <p class="hinweis" style="margin-bottom:10px;">
      Gleiche Absenderangaben wie beim Anmeldelink. Kommt diese Nachricht nicht an,
      liegt es am Mailversand — nicht an der Tippgeber-App.
    </p>
    <?php if ($testErgebnis !== null): ?>
      <?php [$ok, $ziel] = $testErgebnis; ?>
      <p class="<?php echo $ok ? 'ok' : 'schlecht'; ?>" style="margin-bottom:10px;">
        <?php if ($ok === null): ?>
          „<?php echo htmlspecialchars($ziel); ?>“ ist keine gültige Adresse.
        <?php elseif ($ok): ?>
          Der Server hat die Nachricht an <?php echo htmlspecialchars($ziel); ?> übergeben.
          Kommt sie trotzdem nicht an, prüfen Sie den Spam-Ordner.
        <?php else: ?>
          Der Server konnte die Nachricht nicht übergeben — hier liegt die Ursache.
        <?php endif; ?>
      </p>
    <?php endif; ?>
    <form method="post" style="display:flex; gap:10px; flex-wrap:wrap;">
      <input type="email" name="testmail" required placeholder="ihre@adresse.at"
             style="flex:1 1 240px; background:#0f0f11; border:1px solid rgba(255,255,255,0.14); color:#fff; border-radius:8px; padding:10px 12px; font-size:14px; font-family:inherit;">
      <button type="submit" style="background:#fbe48b; color:#17161a; border:0; border-radius:100px; padding:10px 22px; font-weight:700; font-size:13.5px; cursor:pointer; font-family:inherit;">
        Testnachricht senden
      </button>
    </form>
  </div>
</div>
</body>
</html>
