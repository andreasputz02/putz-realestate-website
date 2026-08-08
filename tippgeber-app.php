<?php
// ============================================================
//  Tippgeber-Bereich — die eigentliche Web-App
//
//  Zeigt dem angemeldeten Tippgeber seine eigenen Empfehlungen,
//  deren Stand und die Provision. Abgefragt wird ausschliesslich
//  ueber die ID aus der Sitzung — ein Tippgeber kann die Daten
//  eines anderen also nicht sehen, auch nicht durch Herumspielen
//  an der Adresszeile.
// ============================================================

require_once __DIR__ . '/tippgeber-db.php';
session_start();

if (empty($_SESSION['tippgeber_id'])) {
    header('Location: tippgeber-login.php');
    exit;
}

if (isset($_GET['abmelden'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: tippgeber-login.php');
    exit;
}

try {
    $db = tg_db();
    $stmt = $db->prepare("SELECT * FROM tippgeber WHERE id = ?");
    $stmt->execute([(int)$_SESSION['tippgeber_id']]);
    $ich = $stmt->fetch();

    if (!$ich) {                    // Konto wurde entfernt
        $_SESSION = [];
        session_destroy();
        header('Location: tippgeber-login.php');
        exit;
    }

    $stmt = $db->prepare("
        SELECT * FROM empfehlungen
        WHERE tippgeber_id = ?
        ORDER BY zeitpunkt DESC, id DESC
    ");
    $stmt->execute([(int)$ich['id']]);
    $empfehlungen = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('Tippgeber-App: ' . $e->getMessage());
    http_response_code(500);
    exit('Der Tippgeber-Bereich ist gerade nicht erreichbar. Bitte später erneut versuchen.');
}

// --- Kennzahlen ---
$statusListe = tg_status_liste();
$offen = $vermittelt = 0;
$provisionOffen = $provisionBezahlt = 0.0;

foreach ($empfehlungen as $e) {
    $betrag = (float)($e['provision'] ?? 0);
    if ($e['status'] === 'verkauft') {
        $vermittelt++;
        if ((int)$e['provision_bezahlt'] === 1) $provisionBezahlt += $betrag;
        else                                    $provisionOffen   += $betrag;
    } elseif ($e['status'] !== 'abgelehnt') {
        $offen++;
        $provisionOffen += $betrag;
    }
}

// --- Persoenlicher Empfehlungslink ---
require_once __DIR__ . '/ref-token.php';
$empfehlungslink = SITE_URL . '/bewertung.html?ref='
    . build_ref_token($ich['vorname'], $ich['nachname'], $ich['email']);

function geld(float $b): string { return '€ ' . number_format($b, 0, ',', '.'); }
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#0b0b0c">
<meta name="robots" content="noindex, nofollow">
<title>Mein Tippgeber-Bereich — PUTZ Real Estate</title>
<link rel="stylesheet" href="css/fonts.css?v=3">
<link rel="stylesheet" href="css/style.css?v=122">
</head>
<body class="page-dark tg-app-body">

<header class="tg-kopf">
  <a href="index.html" class="tg-kopf-logo"><img src="assets/img/logo.png" alt="PUTZ Real Estate"></a>
  <a href="?abmelden=1" class="tg-abmelden">Abmelden</a>
</header>

<main class="tg-app">

  <section class="tg-begruessung">
    <p class="eyebrow on-dark">Tippgeber-Bereich</p>
    <h1>Hallo <?php echo htmlspecialchars($ich['vorname'] ?: 'und willkommen'); ?>!</h1>
  </section>

  <section class="tg-kennzahlen">
    <div class="tg-kachel">
      <strong><?php echo count($empfehlungen); ?></strong>
      <span>Empfehlungen gesamt</span>
    </div>
    <div class="tg-kachel">
      <strong><?php echo $offen; ?></strong>
      <span>In Bearbeitung</span>
    </div>
    <div class="tg-kachel">
      <strong><?php echo $vermittelt; ?></strong>
      <span>Erfolgreich vermittelt</span>
    </div>
    <div class="tg-kachel ist-gold">
      <strong><?php echo geld($provisionBezahlt); ?></strong>
      <span>Bereits ausbezahlt</span>
    </div>
  </section>

  <?php if ($provisionOffen > 0): ?>
  <p class="tg-hinweis">
    Zusätzlich <strong><?php echo geld($provisionOffen); ?></strong> in Aussicht —
    aus Empfehlungen, die noch laufen oder deren Auszahlung ansteht.
  </p>
  <?php endif; ?>

  <section class="tg-abschnitt">
    <h2>Dein Empfehlungslink</h2>
    <p class="tg-lede">
      Wer über diesen Link ein Formular ausfüllt, wird dir automatisch zugeordnet.
    </p>
    <div class="tg-link-box">
      <input type="text" id="tg-link" value="<?php echo htmlspecialchars($empfehlungslink); ?>" readonly>
      <button type="button" class="btn btn-gold" data-kopieren>Kopieren</button>
    </div>
    <p class="tg-teilen">
      Direkt teilen:
      <a href="https://wa.me/?text=<?php echo rawurlencode('Ich kann dir PUTZ Real Estate empfehlen — kostenlose Immobilienbewertung: ' . $empfehlungslink); ?>" target="_blank" rel="noopener">WhatsApp</a>
      ·
      <a href="mailto:?subject=<?php echo rawurlencode('Immobilienbewertung'); ?>&body=<?php echo rawurlencode('Ich kann dir PUTZ Real Estate empfehlen: ' . $empfehlungslink); ?>">E-Mail</a>
    </p>
  </section>

  <section class="tg-abschnitt">
    <h2>Deine Empfehlungen</h2>

    <?php if (!$empfehlungen): ?>
      <div class="tg-leer">
        <p>Noch keine Empfehlungen eingegangen.</p>
        <p class="tg-lede">Teile deinen Link — sobald jemand darüber ein Formular ausfüllt, erscheint die Empfehlung hier.</p>
      </div>
    <?php else: ?>
      <div class="tg-liste">
        <?php foreach ($empfehlungen as $e): ?>
          <article class="tg-eintrag">
            <div class="tg-eintrag-kopf">
              <strong><?php echo htmlspecialchars($e['name'] ?: 'Ohne Namen'); ?></strong>
              <span class="tg-status ist-<?php echo htmlspecialchars($e['status']); ?>">
                <?php echo htmlspecialchars($statusListe[$e['status']] ?? $e['status']); ?>
              </span>
            </div>
            <p class="tg-eintrag-meta">
              <?php echo htmlspecialchars($e['zeitpunkt']); ?>
              <?php if ($e['formular'] !== ''): ?> · <?php echo htmlspecialchars($e['formular']); ?><?php endif; ?>
              <?php if ($e['objekt'] !== ''): ?> · <?php echo htmlspecialchars($e['objekt']); ?><?php endif; ?>
            </p>
            <?php if ($e['provision'] !== null && (float)$e['provision'] > 0): ?>
              <p class="tg-provision">
                Provision <strong><?php echo geld((float)$e['provision']); ?></strong>
                — <?php echo (int)$e['provision_bezahlt'] === 1 ? 'ausbezahlt' : 'noch offen'; ?>
              </p>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <p class="tg-fuss">
    Fragen? <a href="mailto:office@putzrealestate.at">office@putzrealestate.at</a>
    · <a href="tel:+4366643500662">0664 3500662</a>
  </p>
</main>

<script>
  // Empfehlungslink in die Zwischenablage legen
  document.querySelector('[data-kopieren]')?.addEventListener('click', async (e) => {
    const feld = document.getElementById('tg-link');
    const knopf = e.currentTarget;
    try {
      await navigator.clipboard.writeText(feld.value);
    } catch {
      // Aeltere Browser oder fehlende Berechtigung: markieren, damit
      // der Nutzer selbst kopieren kann.
      feld.select();
      document.execCommand('copy');
    }
    const alt = knopf.textContent;
    knopf.textContent = 'Kopiert';
    setTimeout(() => { knopf.textContent = alt; }, 1600);
  });
</script>

</body>
</html>
