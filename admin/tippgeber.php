<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$dataFile = __DIR__ . '/../data/tippgeber.json';
$entries = [];
if (file_exists($dataFile)) {
    $decoded = json_decode(file_get_contents($dataFile), true);
    if (is_array($decoded)) $entries = $decoded;
}
$entries = array_reverse($entries);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Tippgeber-Übersicht — PUTZ Real Estate</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, "Inter Tight", Arial, sans-serif; background: #0b0b0c; color: #fff; padding: 40px 24px; }
  .wrap { max-width: 1150px; margin: 0 auto; }
  .top { display: flex; align-items: flex-end; justify-content: space-between; margin-bottom: 28px; flex-wrap: wrap; gap: 16px; }
  h1 { font-size: 24px; font-weight: 600; }
  .count { color: rgba(255,255,255,0.5); font-size: 13.5px; margin-top: 4px; }
  a.logout { color: #cfa858; font-size: 13.5px; text-decoration: underline; }
  .scroll { overflow-x: auto; border-radius: 12px; border: 1px solid rgba(255,255,255,0.08); }
  table { width: 100%; border-collapse: collapse; background: #131211; }
  th, td { text-align: left; padding: 14px 16px; font-size: 13.5px; border-bottom: 1px solid rgba(255,255,255,0.08); white-space: nowrap; }
  th { color: rgba(255,255,255,0.5); text-transform: uppercase; letter-spacing: 0.05em; font-size: 11px; font-weight: 700; }
  tr:last-child td { border-bottom: none; }
  td a { color: #cfa858; }
  .empty { color: rgba(255,255,255,0.5); padding: 60px 20px; text-align: center; }
</style>
</head>
<body>
  <div class="wrap">
    <div class="top">
      <div>
        <h1>Tippgeber-Übersicht</h1>
        <p class="count"><?php echo count($entries); ?> registrierte Tippgeber</p>
      </div>
      <a class="logout" href="logout.php">Abmelden</a>
    </div>

    <?php if (empty($entries)): ?>
      <div class="scroll"><p class="empty">Noch keine Registrierungen.</p></div>
    <?php else: ?>
    <div class="scroll">
      <table>
        <thead>
          <tr>
            <th>Datum</th>
            <th>Vorname</th>
            <th>Nachname</th>
            <th>Firma</th>
            <th>Telefon</th>
            <th>E-Mail</th>
            <th>IBAN</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($entries as $e): ?>
          <tr>
            <td><?php echo htmlspecialchars($e['timestamp'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($e['vorname'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($e['nachname'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($e['firma'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($e['telefon'] ?? ''); ?></td>
            <td><a href="mailto:<?php echo htmlspecialchars($e['email'] ?? ''); ?>"><?php echo htmlspecialchars($e['email'] ?? ''); ?></a></td>
            <td><?php echo htmlspecialchars($e['iban'] ?? ''); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</body>
</html>
