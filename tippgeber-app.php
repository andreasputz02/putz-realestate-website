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
//
// Ziel ist empfehlung.html — nur dort traegt das Formular das
// versteckte ref-Feld. Auf jeder anderen Seite ginge die Zuordnung
// zum Tippgeber verloren. Dieselbe Adresse steht in der
// Bestaetigungsmail nach der Registrierung (send-mail.php).
require_once __DIR__ . '/ref-token.php';
$empfehlungslink = SITE_URL . '/empfehlung.html?ref='
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
<link rel="manifest" href="tippgeber-app.webmanifest">
<link rel="apple-touch-icon" href="assets/img/app-symbol-192.png">
<link rel="stylesheet" href="css/fonts.css?v=4">
<link rel="stylesheet" href="css/style.css?v=151">
</head>
<body class="page-dark tg-app-body">

<header class="tg-kopf">
  <a href="index.html" class="tg-kopf-logo"><img src="assets/img/logo.png" alt="PUTZ Real Estate"></a>
  <a href="?abmelden=1" class="tg-abmelden">Abmelden</a>
</header>

<main class="tg-app">

  <section class="tg-begruessung">
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
      <p class="tg-lede" style="margin-bottom:14px;">Zum Aufklappen antippen.</p>
      <div class="tg-liste">
        <?php foreach ($empfehlungen as $e): ?>
          <?php
            $bezahlt   = (int)$e['provision_bezahlt'] === 1;
            $verkauft  = $e['status'] === 'verkauft';
            $betrag    = $e['provision'] !== null ? (float)$e['provision'] : null;
            $hatObjekt = trim($e['objekt_titel'] ?? '') !== '' || trim($e['objekt_seite'] ?? '') !== '';
          ?>
          <!-- <details> statt eigener Klapp-Logik: funktioniert auch ohne
               JavaScript und ist für Screenreader von Haus aus richtig. -->
          <details class="tg-eintrag">
            <summary>
              <span class="tg-eintrag-kopf">
                <strong><?php echo htmlspecialchars($e['name'] ?: 'Ohne Namen'); ?></strong>
                <span class="tg-status ist-<?php echo htmlspecialchars($e['status']); ?>">
                  <?php echo htmlspecialchars($statusListe[$e['status']] ?? $e['status']); ?>
                </span>
              </span>
              <span class="tg-eintrag-meta">
                <?php echo htmlspecialchars($e['zeitpunkt']); ?>
                <?php if ($hatObjekt): ?> · Objekt online<?php endif; ?>
                <?php if ($betrag !== null && $betrag > 0): ?> · <?php echo geld($betrag); ?><?php endif; ?>
              </span>
            </summary>

            <div class="tg-eintrag-inhalt">

              <?php if ($hatObjekt): ?>
                <div class="tg-objekt">
                  <p class="tg-feld-titel">Das Objekt dazu</p>
                  <p class="tg-objekt-name"><?php echo htmlspecialchars($e['objekt_titel'] ?: 'Objekt'); ?></p>
                  <?php if (trim($e['objekt_seite']) !== ''): ?>
                    <a class="btn btn-gold tg-objekt-knopf" href="<?php echo htmlspecialchars($e['objekt_seite']); ?>" target="_blank" rel="noopener">
                      Objekt ansehen
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                    </a>
                  <?php endif; ?>
                </div>
              <?php endif; ?>

              <p class="tg-feld-titel">Stand</p>
              <p class="tg-feld-wert"><?php echo htmlspecialchars($statusListe[$e['status']] ?? $e['status']); ?></p>

              <?php if ($betrag !== null && $betrag > 0): ?>
                <p class="tg-feld-titel">Deine Prämie</p>
                <p class="tg-betrag"><?php echo geld($betrag); ?></p>

                <?php if ($e['verkaufspreis'] && $e['provisionssatz']): ?>
                  <p class="tg-rechenweg">
                    Verkaufspreis <?php echo geld((float)$e['verkaufspreis']); ?>
                    · unsere Provision <?php echo rtrim(rtrim(number_format((float)$e['provisionssatz'], 2, ',', '.'), '0'), ','); ?> %
                    · Dein Anteil <?php echo rtrim(rtrim(number_format((float)($e['anteil_prozent'] ?: 20), 2, ',', '.'), '0'), ','); ?> %
                  </p>
                <?php endif; ?>

                <?php if ($bezahlt): ?>
                  <p class="tg-vermerk ist-gut">Bereits ausbezahlt.</p>
                <?php elseif ($verkauft): ?>
                  <p class="tg-vermerk">Die Auszahlung steht noch aus — wir melden uns bei dir.</p>
                <?php else: ?>
                  <!-- Ausdrücklicher Vorbehalt: solange nicht verkauft ist,
                       ist der Betrag eine Momentaufnahme, keine Zusage. -->
                  <p class="tg-vermerk ist-vorbehalt">
                    <strong>Vorläufiger Wert.</strong> So viel wäre deine Prämie beim derzeit
                    angesetzten Preis. Fällt der tatsächliche Verkaufspreis niedriger aus,
                    verringert sich der Betrag entsprechend.
                  </p>
                <?php endif; ?>
              <?php else: ?>
                <p class="tg-feld-titel">Deine Prämie</p>
                <p class="tg-vermerk">
                  Steht noch nicht fest. Sobald ein Verkaufspreis feststeht, erscheint
                  hier dein Anteil samt Rechenweg.
                </p>
              <?php endif; ?>

              <p class="tg-eintrag-fuss">
                Empfohlen am <?php echo htmlspecialchars($e['zeitpunkt']); ?>
                <?php if ($e['formular'] !== ''): ?> · über <?php echo htmlspecialchars($e['formular']); ?><?php endif; ?>
              </p>
            </div>
          </details>
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

  // Zum Startbildschirm hinzufügbar machen. Der Service Worker speichert
  // bewusst nur Gestaltung zwischen, nie die Seiten selbst — sonst
  // könnten veraltete Zahlen erscheinen.
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('tippgeber-sw.js').catch(() => {
        // Kein Grund für eine Fehlermeldung: ohne Service Worker
        // funktioniert der Bereich als ganz normale Website weiter.
      });
    });
  }
</script>

</body>
</html>
