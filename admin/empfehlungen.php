<?php
// ============================================================
//  Empfehlungen pflegen
//
//  Hier setzt der Admin den Stand jeder Empfehlung und traegt die
//  Provision ein. Genau diese Angaben sieht der Tippgeber danach
//  in seinem Bereich — nichts davon berechnet sich von selbst.
// ============================================================

session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

require_once dirname(__DIR__) . '/tippgeber-db.php';

$statusListe = tg_status_liste();
$meldung = '';

// Einmal-Token gegen untergeschobene Formulare von fremden Seiten.
if (empty($_SESSION['admin_token'])) {
    $_SESSION['admin_token'] = bin2hex(random_bytes(16));
}

try {
    $db = tg_db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['admin_token'], $_POST['token'] ?? '')) {
            $meldung = 'Die Sitzung ist abgelaufen. Bitte erneut speichern.';
        } else {
            $id        = (int)($_POST['id'] ?? 0);
            $status    = (string)($_POST['status'] ?? 'eingegangen');
            if (!isset($statusListe[$status])) $status = 'eingegangen';

            // Zahlen tolerant lesen: "1.500,50", "1500.5", "1500 €" und ""
            // sollen alle funktionieren — so tippt man Betraege tatsaechlich.
            $zahl = function ($roh): ?float {
                $roh = trim((string)$roh);
                if ($roh === '') return null;
                $roh = str_replace(['.', ' ', '€', '%'], '', $roh);
                $roh = str_replace(',', '.', $roh);
                return is_numeric($roh) ? (float)$roh : null;
            };

            $verkaufspreis = $zahl($_POST['verkaufspreis'] ?? '');
            $satz          = $zahl($_POST['provisionssatz'] ?? '');
            $anteil        = $zahl($_POST['anteil'] ?? '') ?: TG_ANTEIL_STANDARD;
            $ueberschrieben = $zahl($_POST['provision'] ?? '');

            // Gerechnet wird aus Preis und Satz; ein eingetragener Betrag
            // gewinnt aber immer — fuer Sonderfaelle.
            $provision = $ueberschrieben ?? tg_provision_rechnen($verkaufspreis, $satz, $anteil);

            // Objekt zuordnen. Der Wert kommt aus der Auswahlliste und
            // traegt Kennung, Titel und Seitenadresse in einem Feld.
            $objektId = $objektTitel = $objektSeite = '';
            $wahl = trim((string)($_POST['objekt_wahl'] ?? ''));
            if ($wahl !== '') {
                $teile = explode('|', $wahl, 3);
                $objektId    = $teile[0] ?? '';
                $objektTitel = $teile[1] ?? '';
                $objektSeite = $teile[2] ?? '';
            }

            // Vorherigen Stand merken, um zu erkennen, was neu ist.
            $vor = $db->prepare("SELECT status, objekt_id FROM empfehlungen WHERE id = ?");
            $vor->execute([$id]);
            $davor = $vor->fetch() ?: ['status' => '', 'objekt_id' => ''];

            $stmt = $db->prepare("
                UPDATE empfehlungen
                SET status = ?, provision = ?, provision_bezahlt = ?, notiz = ?,
                    verkaufspreis = ?, provisionssatz = ?, anteil_prozent = ?,
                    objekt_id = ?, objekt_titel = ?, objekt_seite = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $status,
                $provision,
                empty($_POST['bezahlt']) ? 0 : 1,
                trim((string)($_POST['notiz'] ?? '')),
                $verkaufspreis,
                $satz,
                $anteil,
                $objektId,
                $objektTitel,
                $objektSeite,
                $id,
            ]);
            $meldung = 'Gespeichert.';

            // Benachrichtigungen — nur an den beiden Wendepunkten und
            // nur, wenn sie durch dieses Speichern erstmals eintreten.
            if ($objektId !== '' && $davor['objekt_id'] === '') {
                tg_benachrichtigen($db, $id, 'online');
                $meldung .= ' Der Tippgeber wurde über das Objekt informiert.';
            }
            if ($status === 'verkauft' && $davor['status'] !== 'verkauft') {
                tg_benachrichtigen($db, $id, 'verkauft');
                $meldung .= ' Der Tippgeber wurde über den Verkauf informiert.';
            }
        }
    }

    $empfehlungen = $db->query("
        SELECT e.*, t.vorname AS tg_vorname, t.nachname AS tg_nachname
        FROM empfehlungen e
        LEFT JOIN tippgeber t ON t.id = e.tippgeber_id
        ORDER BY e.zeitpunkt DESC, e.id DESC
    ")->fetchAll();

    // Auswahlliste der Objekte aus dem Justimmo-Zwischenspeicher.
    // Bewusst aus der Datei statt per API-Abruf: die Seite soll schnell
    // laden und nicht bei jedem Aufruf Justimmo befragen.
    $objekte = [];
    $cacheDatei = dirname(__DIR__) . '/data/justimmo-cache.json';
    if (is_file($cacheDatei)) {
        foreach (json_decode((string)file_get_contents($cacheDatei), true) ?: [] as $o) {
            $objekte[] = [
                'id'    => (string)($o['objektId'] ?? ''),
                'titel' => (string)($o['title'] ?? ''),
                'seite' => 'https://putz-realestate.at/immobilie.html?id=' . rawurlencode((string)($o['id'] ?? '')),
            ];
        }
    }
} catch (Throwable $e) {
    error_log('Admin-Empfehlungen: ' . $e->getMessage());
    exit('Die Datenbank ist gerade nicht erreichbar: ' . htmlspecialchars($e->getMessage()));
}

$offenSumme = 0.0;
$bezahltSumme = 0.0;
foreach ($empfehlungen as $e) {
    $b = (float)($e['provision'] ?? 0);
    if ((int)$e['provision_bezahlt'] === 1) $bezahltSumme += $b;
    elseif ($e['status'] !== 'abgelehnt')   $offenSumme   += $b;
}
function geldA(float $b): string { return '€ ' . number_format($b, 2, ',', '.'); }
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Empfehlungen — PUTZ Real Estate</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, Arial, sans-serif; background: #0b0b0c; color: #fff; padding: 40px 24px; line-height: 1.55; }
  .wrap { max-width: 1000px; margin: 0 auto; }
  h1 { font-size: 24px; font-weight: 600; }
  .count { color: rgba(255,255,255,0.5); font-size: 13.5px; margin-top: 4px; }
  a.logout { color: #cfa858; font-size: 13.5px; text-decoration: underline; }
  a.logout + a.logout { margin-left: 18px; }
  .top { display: flex; align-items: flex-end; justify-content: space-between; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }

  .summe { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 26px; }
  .summe div { background: #141416; border: 1px solid rgba(255,255,255,0.09); border-radius: 10px; padding: 14px 18px; }
  .summe strong { display: block; font-size: 20px; font-weight: 700; }
  .summe span { font-size: 11px; letter-spacing: .1em; text-transform: uppercase; color: rgba(255,255,255,0.5); }

  .meldung { background: rgba(127,209,139,0.16); color: #8fd89b; border-radius: 8px; padding: 11px 15px; font-size: 13.5px; margin-bottom: 20px; }

  .karte { background: #141416; border: 1px solid rgba(255,255,255,0.09); border-radius: 12px; padding: 20px 22px; margin-bottom: 14px; }
  .kopf { display: flex; justify-content: space-between; gap: 14px; flex-wrap: wrap; align-items: baseline; }
  .kopf strong { font-size: 16px; }
  .meta { font-size: 12.5px; color: rgba(255,255,255,0.45); margin-top: 4px; }
  .meta a { color: #cfa858; }
  .von { font-size: 13px; color: rgba(255,255,255,0.7); margin-top: 8px; }
  .von b { color: #fbe48b; font-weight: 600; }

  form.pflege { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; margin-top: 16px; padding-top: 16px; border-top: 1px solid rgba(255,255,255,0.08); }
  form.pflege label { display: block; font-size: 11px; letter-spacing: .08em; text-transform: uppercase; color: rgba(255,255,255,0.45); margin-bottom: 5px; }
  select, input[type=text] { background: #0f0f11; border: 1px solid rgba(255,255,255,0.14); color: #fff; border-radius: 8px; padding: 9px 11px; font-size: 13.5px; font-family: inherit; }
  input[type=text] { width: 130px; }
  .notiz input { width: 230px; }
  .bezahlt { display: flex; align-items: center; gap: 7px; font-size: 13px; color: rgba(255,255,255,0.75); padding-bottom: 9px; }
  button { background: #fbe48b; color: #17161a; border: 0; border-radius: 100px; padding: 10px 20px; font-weight: 700; font-size: 13.5px; cursor: pointer; font-family: inherit; }
  .leer { color: rgba(255,255,255,0.5); padding: 60px 20px; text-align: center; background: #141416; border-radius: 12px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div>
      <h1>Empfehlungen</h1>
      <p class="count"><?php echo count($empfehlungen); ?> gesamt</p>
    </div>
    <div>
      <a class="logout" href="tippgeber.php">Tippgeber</a>
      <a class="logout" href="system.php">Systemprüfung</a>
      <a class="logout" href="logout.php">Abmelden</a>
    </div>
  </div>

  <div class="summe">
    <div><strong><?php echo geldA($offenSumme); ?></strong><span>Provision offen</span></div>
    <div><strong><?php echo geldA($bezahltSumme); ?></strong><span>Bereits ausbezahlt</span></div>
  </div>

  <?php if ($meldung !== ''): ?>
    <div class="meldung"><?php echo htmlspecialchars($meldung); ?></div>
  <?php endif; ?>

  <?php if (!$empfehlungen): ?>
    <div class="leer">
      Noch keine Empfehlungen. Sie entstehen, sobald jemand über den Link eines
      Tippgebers ein Formular abschickt.
    </div>
  <?php endif; ?>

  <?php foreach ($empfehlungen as $e): ?>
    <div class="karte">
      <div class="kopf">
        <div>
          <strong><?php echo htmlspecialchars($e['name'] ?: 'Ohne Namen'); ?></strong>
          <p class="meta">
            <?php echo htmlspecialchars($e['zeitpunkt']); ?>
            <?php if ($e['formular']): ?> · <?php echo htmlspecialchars($e['formular']); ?><?php endif; ?>
            <?php if ($e['objekt']): ?> · <?php echo htmlspecialchars($e['objekt']); ?><?php endif; ?>
            <?php if ($e['email']): ?> · <a href="mailto:<?php echo htmlspecialchars($e['email']); ?>"><?php echo htmlspecialchars($e['email']); ?></a><?php endif; ?>
            <?php if ($e['telefon']): ?> · <a href="tel:<?php echo htmlspecialchars($e['telefon']); ?>"><?php echo htmlspecialchars($e['telefon']); ?></a><?php endif; ?>
          </p>
          <p class="von">
            Empfohlen von <b><?php
              $n = trim(($e['tg_vorname'] ?? '') . ' ' . ($e['tg_nachname'] ?? ''));
              echo htmlspecialchars($n !== '' ? $n : ($e['tippgeber_email'] ?: 'unbekannt'));
            ?></b>
            <?php if ($e['tippgeber_id'] === null): ?>
              <span style="color:#f0c674;">— nicht als Tippgeber registriert, sieht die Empfehlung nicht</span>
            <?php endif; ?>
          </p>
        </div>
      </div>

      <form method="post" class="pflege">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($_SESSION['admin_token']); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$e['id']; ?>">

        <div>
          <label for="s<?php echo $e['id']; ?>">Stand</label>
          <select id="s<?php echo $e['id']; ?>" name="status">
            <?php foreach ($statusListe as $wert => $text): ?>
              <option value="<?php echo $wert; ?>" <?php echo $e['status'] === $wert ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($text); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label for="vp<?php echo $e['id']; ?>">Verkaufspreis €</label>
          <input type="text" id="vp<?php echo $e['id']; ?>" name="verkaufspreis" inputmode="decimal"
                 value="<?php echo $e['verkaufspreis'] !== null ? htmlspecialchars(number_format((float)$e['verkaufspreis'], 0, ',', '')) : ''; ?>"
                 placeholder="500000">
        </div>

        <div>
          <label for="ps<?php echo $e['id']; ?>">Unsere Prov. %</label>
          <input type="text" id="ps<?php echo $e['id']; ?>" name="provisionssatz" inputmode="decimal" style="width:90px;"
                 value="<?php echo $e['provisionssatz'] !== null ? htmlspecialchars(rtrim(rtrim(number_format((float)$e['provisionssatz'], 2, ',', ''), '0'), ',')) : ''; ?>"
                 placeholder="3">
        </div>

        <div>
          <label for="an<?php echo $e['id']; ?>">Anteil %</label>
          <input type="text" id="an<?php echo $e['id']; ?>" name="anteil" inputmode="decimal" style="width:80px;"
                 value="<?php echo htmlspecialchars(rtrim(rtrim(number_format((float)($e['anteil_prozent'] ?: TG_ANTEIL_STANDARD), 2, ',', ''), '0'), ',')); ?>">
        </div>

        <div>
          <label for="p<?php echo $e['id']; ?>">Betrag € <span style="text-transform:none;letter-spacing:0;">(überschreibt)</span></label>
          <input type="text" id="p<?php echo $e['id']; ?>" name="provision" inputmode="decimal"
                 value="<?php echo $e['provision'] !== null ? htmlspecialchars(number_format((float)$e['provision'], 2, ',', '')) : ''; ?>"
                 placeholder="wird gerechnet">
        </div>

        <label class="bezahlt">
          <input type="checkbox" name="bezahlt" value="1" <?php echo (int)$e['provision_bezahlt'] === 1 ? 'checked' : ''; ?>>
          ausbezahlt
        </label>

        <div class="notiz">
          <label for="o<?php echo $e['id']; ?>">Objekt zuordnen</label>
          <select id="o<?php echo $e['id']; ?>" name="objekt_wahl" style="width:260px;">
            <option value="">— noch keines —</option>
            <?php foreach ($objekte as $o): ?>
              <?php $wert = $o['id'] . '|' . $o['titel'] . '|' . $o['seite']; ?>
              <option value="<?php echo htmlspecialchars($wert); ?>"
                      <?php echo (string)$e['objekt_id'] === $o['id'] && $o['id'] !== '' ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars(mb_strimwidth($o['titel'], 0, 46, '…')); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="notiz">
          <label for="n<?php echo $e['id']; ?>">Interne Notiz</label>
          <input type="text" id="n<?php echo $e['id']; ?>" name="notiz" value="<?php echo htmlspecialchars($e['notiz'] ?? ''); ?>">
        </div>

        <button type="submit">Speichern</button>
      </form>
    </div>
  <?php endforeach; ?>
</div>
</body>
</html>
