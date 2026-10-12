<?php
// ============================================================
//  Uebersicht der Suchprofile (nur fuer uns)
//
//  Aufruf: /suchprofil-verwaltung.php?schluessel=<SP_SCHLUESSEL>
//
//  Zeigt alle Profile mit Status und Wuenschen und nimmt bestehende
//  Interessenten auf, die sich frueher nur per Mail gemeldet haben:
//  Adressen einfuegen, abschicken, jede bekommt eine Mail mit dem
//  Bestaetigungslink. Ohne diesen Klick wird nichts verschickt.
// ============================================================

require_once __DIR__ . '/suchprofil-lib.php';
if (!defined('SP_SCHLUESSEL')) define('SP_SCHLUESSEL', 'putz-suchprofile-2026');

if (($_GET['schluessel'] ?? $_POST['schluessel'] ?? '') !== SP_SCHLUESSEL) {
    http_response_code(403);
    exit('Kein Zugriff.');
}

$meldung = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['adressen'])) {
    $angelegt = $schon = $fehler = 0;
    foreach (preg_split('/\r?\n/', (string)$_POST['adressen']) as $zeile) {
        $zeile = trim($zeile);
        if ($zeile === '') continue;
        // Erlaubt: "mail@example.at" oder "mail@example.at; Vorname; Nachname"
        $teile = array_map('trim', preg_split('/[;,]/', $zeile));
        $daten = [
            'email'    => $teile[0] ?? '',
            'vorname'  => $teile[1] ?? '',
            'nachname' => $teile[2] ?? '',
        ];
        $profil = sp_aufnehmen($daten);
        if (!$profil) { $fehler++; continue; }
        if (!empty($profil['bestaetigt'])) { $schon++; continue; }
        sp_bestaetigung_senden($profil);
        $angelegt++;
    }
    $meldung = "$angelegt Einladung(en) verschickt, $schon schon bestätigt, $fehler ungültig.";
}

$profile = sp_db()->query('SELECT * FROM profile ORDER BY erstellt DESC')->fetchAll(PDO::FETCH_ASSOC);
$anzahl = sp_db()->query('SELECT COUNT(*) FROM profile WHERE bestaetigt IS NOT NULL AND abgemeldet IS NULL')->fetchColumn();

function h($t) { return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); }

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Suchprofile</title>
<style>
  body { background: #0b0b0c; color: #f1eee8; font: 15px/1.6 system-ui, sans-serif; margin: 0; padding: 32px 20px 60px; }
  .blatt { max-width: 1100px; margin: 0 auto; }
  h1 { font-size: 26px; margin: 0 0 6px; }
  .leise { color: rgba(241, 238, 232, 0.6); }
  table { width: 100%; border-collapse: collapse; margin-top: 22px; font-size: 14px; }
  th, td { text-align: left; padding: 9px 10px; border-bottom: 1px solid rgba(255, 255, 255, 0.1); vertical-align: top; }
  th { font-size: 12px; text-transform: uppercase; letter-spacing: 0.06em; color: rgba(241, 238, 232, 0.5); }
  .ja { color: #8cc79a; }
  .offen { color: #e6b05e; }
  .weg { color: #e08a76; }
  form { margin-top: 34px; background: #16161a; border: 1px solid rgba(255, 255, 255, 0.12); border-radius: 12px; padding: 18px; }
  textarea { width: 100%; min-height: 120px; background: #0f0f11; color: inherit; border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 8px; padding: 10px; font: inherit; }
  button { margin-top: 12px; background: #f6d886; color: #0b0b0c; border: 0; border-radius: 100px; padding: 10px 20px; font: 600 14px system-ui; cursor: pointer; }
  .hinweis { background: rgba(246, 216, 134, 0.12); border-left: 3px solid #f6d886; padding: 10px 14px; border-radius: 6px; margin-top: 16px; }
</style>
</head>
<body>
<div class="blatt">
  <h1>Suchprofile</h1>
  <p class="leise"><?= count($profile) ?> insgesamt, <?= (int)$anzahl ?> aktiv (bestätigt und nicht abgemeldet).</p>

  <?php if ($meldung !== ''): ?><div class="hinweis"><?= h($meldung) ?></div><?php endif; ?>

  <table>
    <thead>
      <tr><th>Name</th><th>E-Mail</th><th>Suche</th><th>Status</th><th>Letzte Mail</th></tr>
    </thead>
    <tbody>
    <?php foreach ($profile as $p): ?>
      <tr>
        <td><?= h(trim($p['vorname'] . ' ' . $p['nachname'])) ?><br><span class="leise"><?= h($p['telefon']) ?></span></td>
        <td><?= h($p['email']) ?></td>
        <td class="leise"><?= nl2br(h(sp_wunsch_text($p))) ?><?= $p['wunsch'] !== '' ? '<br>„' . h($p['wunsch']) . '"' : '' ?></td>
        <td>
          <?php if (!empty($p['abgemeldet'])): ?><span class="weg">abgemeldet</span>
          <?php elseif (!empty($p['bestaetigt'])): ?><span class="ja">aktiv</span>
          <?php else: ?><span class="offen">wartet auf Bestätigung</span><?php endif; ?>
          <br><span class="leise"><?= h(substr((string)$p['erstellt'], 0, 10)) ?></span>
        </td>
        <td class="leise"><?= h(substr((string)$p['letzte_mail'], 0, 10)) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$profile): ?><tr><td colspan="5" class="leise">Noch keine Suchprofile.</td></tr><?php endif; ?>
    </tbody>
  </table>

  <form method="post">
    <input type="hidden" name="schluessel" value="<?= h(SP_SCHLUESSEL) ?>">
    <strong>Bestehende Interessenten einladen</strong>
    <p class="leise">Eine Adresse pro Zeile. Optional mit Namen:<br>
      <code>maria@example.at; Maria; Huber</code><br>
      Jede bekommt eine Mail mit dem Bestätigungslink. Erst nach dem Klick verschicken wir Objekte.</p>
    <textarea name="adressen" placeholder="maria@example.at&#10;hans@example.at; Hans; Mayer"></textarea>
    <button type="submit">Einladungen verschicken</button>
  </form>
</div>
</body>
</html>
