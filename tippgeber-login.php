<?php
// ============================================================
//  Anmeldung fuer Tippgeber — ohne Passwort
//
//  Ablauf:
//    1. Tippgeber gibt seine E-Mail-Adresse ein
//    2. Er bekommt einen Link mit einem Einmal-Token zugeschickt
//    3. Der Klick auf den Link meldet ihn an
//
//  Sicherheitsentscheidungen:
//    - Gespeichert wird nur der HASH des Tokens. Wer die Datenbank
//      liest, kann sich damit trotzdem nicht anmelden.
//    - Der Link gilt 30 Minuten und nur ein einziges Mal.
//    - Die Antwort lautet immer gleich, egal ob die Adresse
//      registriert ist. Sonst koennte man ausprobieren, wer
//      Tippgeber ist.
//    - Pro Adresse hoechstens ein Link je Minute, damit niemand
//      fremde Postfaecher zumuellen kann.
// ============================================================

require_once __DIR__ . '/tippgeber-db.php';
require_once __DIR__ . '/mail-versand.php';

session_start();

const TG_LINK_GUELTIG_MINUTEN = 30;
const TG_SPERRE_SEKUNDEN      = 60;

$meldung = '';
$art     = '';   // 'ok' oder 'fehler'

// ------------------------------------------------------------
//  Teil 1 — Klick auf den zugeschickten Link
// ------------------------------------------------------------
if (isset($_GET['token'])) {
    $token = (string)$_GET['token'];
    $hash  = hash('sha256', $token);

    try {
        $db = tg_db();
        $stmt = $db->prepare("
            SELECT l.tippgeber_id, l.gueltig_bis, l.benutzt, t.vorname
            FROM anmeldelinks l
            JOIN tippgeber t ON t.id = l.tippgeber_id
            WHERE l.token_hash = ?
        ");
        $stmt->execute([$hash]);
        $link = $stmt->fetch();

        if (!$link) {
            $meldung = 'Dieser Anmeldelink ist ungültig. Bitte fordere einen neuen an.';
            $art = 'fehler';
        } elseif ((int)$link['benutzt'] === 1) {
            $meldung = 'Dieser Anmeldelink wurde bereits verwendet. Bitte fordere einen neuen an.';
            $art = 'fehler';
        } elseif (strtotime($link['gueltig_bis']) < time()) {
            $meldung = 'Dieser Anmeldelink ist abgelaufen. Bitte fordere einen neuen an.';
            $art = 'fehler';
        } else {
            // Gueltig — Token verbrauchen und anmelden.
            $db->prepare("UPDATE anmeldelinks SET benutzt = 1 WHERE token_hash = ?")->execute([$hash]);
            // Aufgeraeumt wird gleich mit: alles Abgelaufene fliegt raus.
            $db->prepare("DELETE FROM anmeldelinks WHERE gueltig_bis < ?")->execute([date('Y-m-d H:i:s')]);

            session_regenerate_id(true);   // schuetzt vor uebernommenen Sitzungen
            $_SESSION['tippgeber_id'] = (int)$link['tippgeber_id'];
            $_SESSION['tippgeber_seit'] = time();

            header('Location: tippgeber-app.php');
            exit;
        }
    } catch (Throwable $e) {
        error_log('Tippgeber-Anmeldung: ' . $e->getMessage());
        $meldung = 'Die Anmeldung ist gerade nicht möglich. Bitte versuch es später erneut.';
        $art = 'fehler';
    }
}

// ------------------------------------------------------------
//  Teil 2 — Link anfordern
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    // Immer dieselbe Antwort — sie verraet nicht, wer registriert ist.
    $meldung = 'Falls diese Adresse als Tippgeber registriert ist, haben wir dir soeben '
             . 'einen Anmeldelink geschickt. Er gilt ' . TG_LINK_GUELTIG_MINUTEN . ' Minuten.';
    $art = 'ok';

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            $db = tg_db();
            $stmt = $db->prepare("SELECT id, vorname FROM tippgeber WHERE email = ?");
            $stmt->execute([$email]);
            $tg = $stmt->fetch();

            if ($tg) {
                // Sperre: gab es in der letzten Minute schon einen Link?
                $letzter = $db->prepare("
                    SELECT MAX(erstellt) FROM anmeldelinks WHERE tippgeber_id = ?
                ");
                $letzter->execute([$tg['id']]);
                $zuletzt = $letzter->fetchColumn();

                if (!$zuletzt || (time() - strtotime($zuletzt)) >= TG_SPERRE_SEKUNDEN) {
                    $token = bin2hex(random_bytes(32));
                    $db->prepare("
                        INSERT INTO anmeldelinks (token_hash, tippgeber_id, erstellt, gueltig_bis)
                        VALUES (?,?,?,?)
                    ")->execute([
                        hash('sha256', $token),
                        $tg['id'],
                        date('Y-m-d H:i:s'),
                        date('Y-m-d H:i:s', time() + TG_LINK_GUELTIG_MINUTEN * 60),
                    ]);

                    $link = 'https://putz-realestate.at/tippgeber-login.php?token=' . $token;
                    $anrede = trim($tg['vorname']) !== '' ? 'Hallo ' . $tg['vorname'] . ',' : 'Hallo,';

                    $text = "$anrede\n\n"
                          . "hier ist dein Anmeldelink für deinen Tippgeber-Bereich:\n\n"
                          . "$link\n\n"
                          . "Der Link gilt " . TG_LINK_GUELTIG_MINUTEN . " Minuten und kann nur einmal verwendet werden.\n\n"
                          . "Hast du diese Anmeldung nicht angefordert, kannst du diese E-Mail einfach ignorieren — "
                          . "ohne den Link passiert nichts.\n\n"
                          . "Liebe Grüße\nPUTZ Real Estate";

                    nachricht_senden($email, 'Dein Anmeldelink', $text);
                }
            }
        } catch (Throwable $e) {
            error_log('Tippgeber-Anmeldelink: ' . $e->getMessage());
        }
    }
}

// Wer schon angemeldet ist, geht direkt weiter.
if (!empty($_SESSION['tippgeber_id']) && !isset($_GET['token'])) {
    header('Location: tippgeber-app.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#0b0b0c">
<meta name="robots" content="noindex, nofollow">
<title>Tippgeber-Anmeldung — PUTZ Real Estate</title>
<link rel="stylesheet" href="css/fonts.css?v=4">
<link rel="stylesheet" href="css/style.css?v=154">
</head>
<body class="page-dark">

<main class="tg-anmeldung">
  <div class="tg-karte">
    <a href="index.html" class="tg-logo">
      <img src="assets/img/logo.png" alt="PUTZ Real Estate">
    </a>

    <h1>Tippgeber-Bereich</h1>
    <p class="tg-lede">
      Melde dich mit der E-Mail-Adresse an, mit der du dich als Tippgeber registriert hast.
      Du bekommst einen Anmeldelink zugeschickt — kein Passwort nötig.
    </p>

    <?php if ($meldung !== ''): ?>
      <div class="tg-meldung <?php echo $art === 'ok' ? 'ist-ok' : 'ist-fehler'; ?>">
        <?php echo htmlspecialchars($meldung); ?>
      </div>
    <?php endif; ?>

    <form method="post" class="tg-form">
      <div class="field">
        <label for="tg-email">E-Mail-Adresse</label>
        <input type="email" id="tg-email" name="email" required autocomplete="email" autofocus>
      </div>
      <button type="submit" class="btn btn-gold btn-block">
        Anmeldelink anfordern
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
      </button>
    </form>

    <p class="tg-fuss">
      Noch kein Tippgeber? <a href="tippgeber.html">Hier registrieren</a><br>
      <a href="index.html">Zurück zur Website</a>
    </p>
  </div>
</main>

</body>
</html>
