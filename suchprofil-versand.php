<?php
// ============================================================
//  Taeglicher Versand: neue Objekte an die Suchprofile
//
//  Aufruf einmal am Tag ueber den Cronjob im Hostinger-Panel:
//      /usr/bin/php /home/<benutzer>/public_html/suchprofil-versand.php
//
//  Zum Ausprobieren im Browser:
//      /suchprofil-versand.php?schluessel=<SP_SCHLUESSEL>&probe=1
//  "probe=1" zeigt nur an, was verschickt WUERDE, und schickt nichts.
//
//  Verschickt wird hoechstens eine Mail pro Profil und Tag. Objekte, die
//  ein Profil schon bekommen hat, stehen in der Tabelle "gesendet" und
//  kommen nie ein zweites Mal.
// ============================================================

require_once __DIR__ . '/suchprofil-lib.php';
require_once __DIR__ . '/objekte-lib.php';

// Schluessel fuer den Aufruf im Browser. Ueber die Kommandozeile (Cron)
// wird er nicht gebraucht.
if (!defined('SP_SCHLUESSEL')) define('SP_SCHLUESSEL', 'putz-suchprofile-2026');

const SP_HOECHSTENS      = 8;   // so viele Objekte stehen hoechstens in einer Mail
const SP_ERSTE_MAIL_MAX  = 5;   // beim ersten Mal nicht den ganzen Bestand schicken

$ueberKommandozeile = (PHP_SAPI === 'cli');
if (!$ueberKommandozeile) {
    header('Content-Type: text/plain; charset=utf-8');
    if (($_GET['schluessel'] ?? '') !== SP_SCHLUESSEL) {
        http_response_code(403);
        exit("Kein Zugriff.\n");
    }
}
$probe = !$ueberKommandozeile && !empty($_GET['probe']);

$objekte = array_values(array_filter(obj_alle(), fn($o) => empty($o['verkauft'])));
$db = sp_db();
$profile = $db->query('SELECT * FROM profile WHERE bestaetigt IS NOT NULL AND abgemeldet IS NULL')
              ->fetchAll(PDO::FETCH_ASSOC);

echo 'Objekte: ' . count($objekte) . ', aktive Suchprofile: ' . count($profile) . "\n";
// Hilft beim Einrichten des Cronjobs: der genaue Befehl steht damit im Probelauf.
if (!$ueberKommandozeile) echo 'Befehl fuer den Cronjob: ' . (is_file('/usr/bin/php') ? '/usr/bin/php ' : 'php ') . __FILE__ . "\n";

$mails = 0;
foreach ($profile as $p) {
    // Was hat dieses Profil schon bekommen?
    $sql = $db->prepare('SELECT objekt_id FROM gesendet WHERE profil_id = ?');
    $sql->execute([$p['id']]);
    $bekannt = array_flip($sql->fetchAll(PDO::FETCH_COLUMN));
    $erstesMal = count($bekannt) === 0;

    $treffer = [];
    foreach ($objekte as $o) {
        $id = (string)($o['id'] ?? '');
        if ($id === '' || isset($bekannt[$id])) continue;
        if (sp_passt($p, $o)) $treffer[] = $o;
    }
    if (!$treffer) continue;

    // Beim ersten Mal nur eine Auswahl schicken, den Rest als bekannt
    // vermerken — sonst landen zwanzig Objekte auf einmal im Postfach.
    $schicken = $erstesMal ? array_slice($treffer, 0, SP_ERSTE_MAIL_MAX) : array_slice($treffer, 0, SP_HOECHSTENS);

    $zeilen = [];
    foreach ($schicken as $o) {
        $daten = array_filter([
            $o['area']  ?? '',
            !empty($o['rooms']) && $o['rooms'] !== '–' ? $o['rooms'] . ' Zimmer' : '',
            $o['price'] ?? '',
        ], fn($t) => $t !== '' && $t !== '–');
        $zeilen[] = '• ' . ($o['title'] ?? 'Objekt') . "\n"
                  . '  ' . ($o['location'] ?? '') . "\n"
                  . '  ' . implode(' · ', $daten) . "\n"
                  . '  ' . obj_url($o);
    }

    $anrede = trim((string)$p['vorname']) !== '' ? 'Hallo ' . trim((string)$p['vorname']) . ',' : 'Hallo,';
    $wieviele = count($schicken);
    $betreff = $wieviele === 1
        ? 'Ein neues Objekt für deine Suche'
        : $wieviele . ' neue Objekte für deine Suche';

    $text = $anrede . "\n\n"
        . ($erstesMal
            ? "hier sind die Objekte, die gerade zu deiner Suche passen:\n\n"
            : ($wieviele === 1 ? "das ist neu bei uns und passt zu deiner Suche:\n\n"
                               : "das ist neu bei uns und passt zu deiner Suche:\n\n"))
        . implode("\n\n", $zeilen) . "\n\n"
        . "Alle Objekte: " . SITE_URL . "/immobilien\n\n"
        . "Passt etwas nicht? Antworte einfach auf diese Mail, dann stellen wir deine Suche um.\n"
        . "Keine Mails mehr: " . SITE_URL . '/suchprofil?t=' . $p['token'] . "&a=stop\n\n"
        . "Herzliche Grüße\nAndreas Putz\nPUTZ Real Estate GmbH\n"
        . "0664 3500662 · office@putzrealestate.at\n";

    if ($probe) {
        echo "\n--- " . $p['email'] . ' (' . $wieviele . ' von ' . count($treffer) . " Treffern) ---\n" . $text;
        continue;
    }

    if (!nachricht_senden($p['email'], $betreff, $text)) {
        echo 'FEHLER beim Versand an ' . $p['email'] . "\n";
        continue;
    }
    $mails++;

    // Alle Treffer als bekannt vermerken — auch die, die wegen der
    // Hoechstzahl diesmal nicht in der Mail stehen. Sonst kaeme morgen
    // dasselbe Objekt noch einmal.
    $merken = $db->prepare('INSERT OR IGNORE INTO gesendet (profil_id, objekt_id, zeitpunkt) VALUES (?, ?, ?)');
    foreach ($treffer as $o) $merken->execute([$p['id'], (string)$o['id'], date('c')]);
    $db->prepare('UPDATE profile SET letzte_mail = ? WHERE id = ?')->execute([date('c'), $p['id']]);

    echo 'Gesendet an ' . $p['email'] . ': ' . $wieviele . " Objekt(e)\n";
}

if (!$probe) {
    // Letzten Lauf festhalten — daran sieht man in der Uebersicht, ob der
    // Cronjob wirklich jeden Tag greift.
    @file_put_contents(__DIR__ . '/data/suchprofil-lauf.txt',
        date('c') . ' | ' . ($ueberKommandozeile ? 'Cronjob' : 'Browser') . ' | '
        . count($profile) . ' Profile, ' . $mails . " Mail(s)\n", FILE_APPEND);
}
echo ($probe ? "Probelauf beendet.\n" : 'Fertig. Verschickte Mails: ' . $mails . "\n");
