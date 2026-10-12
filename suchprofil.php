<?php
// ============================================================
//  Bestaetigen und Abmelden eines Suchprofils
//
//  /suchprofil?t=<token>&a=ja    -> Suche aktivieren
//  /suchprofil?t=<token>&a=stop  -> keine Mails mehr
//
//  Beide Links stehen in jeder Mail. Es wird bewusst nichts
//  abgefragt: ein Klick genuegt, in beide Richtungen.
// ============================================================

require_once __DIR__ . '/suchprofil-lib.php';

$token = isset($_GET['t']) ? preg_replace('/[^a-f0-9]/', '', (string)$_GET['t']) : '';
$aktion = ($_GET['a'] ?? 'ja') === 'stop' ? 'stop' : 'ja';

$profil = null;
if ($token !== '') {
    $profil = $aktion === 'stop' ? sp_abmelden($token) : sp_bestaetigen($token);
}

if (!$profil) {
    $titel = 'Link nicht mehr gültig';
    $text  = 'Dieser Link gehört zu keiner Suche mehr. Vielleicht wurde sie schon gelöscht. '
           . 'Melde dich einfach neu an oder schreib uns.';
    $zusatz = '';
} elseif ($aktion === 'stop') {
    $titel = 'Abgemeldet';
    $text  = 'Du bekommst keine weiteren Objekte mehr von uns. Deine Suche liegt still, '
           . 'wir melden uns nicht mehr automatisch.';
    $zusatz = '';
} else {
    $titel = 'Deine Suche ist aktiv';
    $text  = 'Ab jetzt schicken wir dir neue passende Objekte, höchstens eine Mail pro Tag. '
           . 'Abmelden kannst du dich mit einem Klick in jeder Mail.';
    $zusatz = nl2br(htmlspecialchars(sp_wunsch_text($profil), ENT_QUOTES, 'UTF-8'));
}

// Stylesheet mit aktueller Versionsnummer aus der Startseite uebernehmen.
$stil = 'css/site.min.css';
$start = @file_get_contents(__DIR__ . '/index.html');
if ($start && preg_match('#href="(css/site\.min\.css\?v=[^"]+)"#', $start, $m)) $stil = $m[1];

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#0b0b0c">
<meta name="robots" content="noindex, nofollow">
<title><?= htmlspecialchars($titel, ENT_QUOTES, 'UTF-8') ?> | PUTZ Real Estate</title>
<link rel="icon" type="image/png" sizes="192x192" href="/assets/img/webicon-192.png">
<link rel="stylesheet" href="<?= htmlspecialchars($stil, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="page-dark">

<main>
  <section class="section section-dark" style="min-height:70vh; display:flex; align-items:center;">
    <div class="container" style="max-width:640px;">
      <p class="eyebrow no-dot">Suchprofil</p>
      <h1 class="kein-punkt" style="font-size:clamp(30px,4vw,44px);"><?= htmlspecialchars($titel, ENT_QUOTES, 'UTF-8') ?><span class="marke-punkt">.</span></h1>
      <p class="lede" style="margin-top:16px;"><?= htmlspecialchars($text, ENT_QUOTES, 'UTF-8') ?></p>
      <?php if ($zusatz !== ''): ?>
        <div class="form-card" style="margin-top:26px;">
          <p class="field-note" style="margin-bottom:8px;">Das ist deine Suche:</p>
          <p style="color:rgba(255,255,255,0.78); font-size:15px; line-height:1.8;"><?= $zusatz ?></p>
        </div>
      <?php endif; ?>
      <div class="hero-actions" style="margin-top:30px;">
        <a href="/immobilien" class="btn btn-gold">Aktuelle Objekte ansehen
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </a>
        <a href="/" class="btn btn-outline">Zur Startseite</a>
      </div>
    </div>
  </section>
</main>

</body>
</html>
