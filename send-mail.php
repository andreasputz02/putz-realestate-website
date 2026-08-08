<?php
header('Content-Type: application/json; charset=utf-8');

// Signatur der Empfehlungslinks — liegt in einer eigenen Datei, weil
// der Tippgeber-Bereich dieselbe Berechnung braucht.
require_once __DIR__ . '/ref-token.php';
// Zentraler Mailversand — setzt Umschlag-Absender, Message-ID und Date.
require_once __DIR__ . '/mail-versand.php';

/**
 * Haengt einen Datensatz an eine JSON-Datei in data/ an.
 *
 * Die Datei wird waehrend des Schreibens gesperrt, damit zwei gleichzeitige
 * Absendungen einander nicht ueberschreiben.
 *
 * Rueckgabe: true bei Erfolg. Ein Fehlschlag darf den Mailversand nie
 * aufhalten — die E-Mail ist der verlaessliche Weg.
 */
function datensatz_anhaengen(string $dateiname, array $eintrag): bool {
    $ordner = __DIR__ . '/data';
    if (!is_dir($ordner) && !mkdir($ordner, 0755, true)) return false;

    $fp = fopen($ordner . '/' . $dateiname, 'c+');
    if (!$fp) return false;

    flock($fp, LOCK_EX);
    $bestand = json_decode(stream_get_contents($fp), true);
    if (!is_array($bestand)) $bestand = [];
    $bestand[] = $eintrag;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($bestand, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

// Sends a plain-text email, optionally with a single file attachment.
function send_mail_maybe_with_attachment($to, $subject, $bodyText, $fromHeader, $replyTo, $attachment = null) {
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    if (!$attachment) {
        $headers = [];
        $headers[] = 'From: ' . $fromHeader;
        $headers[] = 'Reply-To: ' . $replyTo;
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'MIME-Version: 1.0';
        return mail($to, $encodedSubject, $bodyText, implode("\r\n", $headers));
    }

    $boundary = md5(uniqid((string) time(), true));
    $headers = [];
    $headers[] = 'From: ' . $fromHeader;
    $headers[] = 'Reply-To: ' . $replyTo;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

    $message = '--' . $boundary . "\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= $bodyText . "\r\n\r\n";

    $fileContent = chunk_split(base64_encode(file_get_contents($attachment['tmp_path'])));
    $safeName = preg_replace('/[^A-Za-z0-9._\- ]/', '_', $attachment['name']);
    $message .= '--' . $boundary . "\r\n";
    $message .= 'Content-Type: ' . $attachment['type'] . '; name="' . $safeName . '"' . "\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n";
    $message .= 'Content-Disposition: attachment; filename="' . $safeName . '"' . "\r\n\r\n";
    $message .= $fileContent . "\r\n";
    $message .= '--' . $boundary . '--';

    return mail($to, $encodedSubject, $message, implode("\r\n", $headers));
}

// Validates an optional uploaded file field. Returns an attachment array, null (no file
// provided) or triggers a JSON error response and exits if the file is invalid.
function validate_upload($fieldName, $allowedExt, $maxBytes) {
    if (empty($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $file = $_FILES[$fieldName];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'upload_failed']);
        exit;
    }
    if ($file['size'] > $maxBytes) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'file_too_large']);
        exit;
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_file_type']);
        exit;
    }
    return ['tmp_path' => $file['tmp_name'], 'name' => $file['name'], 'type' => $file['type'] ?: 'application/octet-stream'];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

// Honeypot: real users never fill this hidden field, bots often do.
if (!empty($_POST['website'])) {
    echo json_encode(['ok' => true]);
    exit;
}

$to = 'office@putzrealestate.at';

$email = isset($_POST['email']) ? trim($_POST['email']) : '';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_email']);
    exit;
}

$formName = isset($_POST['form_name']) && trim($_POST['form_name']) !== ''
    ? trim($_POST['form_name'])
    : 'Kontaktformular';

$labels = [
    'vorname' => 'Vorname',
    'nachname' => 'Nachname',
    'firma' => 'Firma',
    'email' => 'E-Mail',
    'telefon' => 'Telefon',
    'nummer' => 'Telefonnummer',
    'iban' => 'IBAN',
    'betreff' => 'Betreff',
    'nachricht' => 'Nachricht',
    'objekt' => 'Anfrage betrifft',
    'justimmo_objekt_id' => 'Justimmo-Objektnummer',
    'art' => 'Kauf oder Miete',
    'typ' => 'Immobilienart',
    'lage' => 'Gewünschte Lage',
    'preis' => 'Preis bis',
    'details' => 'Weitere Wünsche',
    'gebiet' => 'Gewünschtes Gebiet',
    'umkreis' => 'Umkreis (km)',
    'immobilientyp' => 'Haus oder Wohnung',
    'zimmer' => 'Zimmer (ab)',
    'groesse' => 'Größe in m² (ab)',
    'sonderwuensche' => 'Weitere Sonderwünsche',
    'adresse' => 'Adresse',
    'grundflaeche' => 'Grundfläche (m²)',
    'wohnflaeche' => 'Wohnfläche (m²)',
    'nutzflaeche' => 'Nutzfläche (m²)',
    'baujahr' => 'Baujahr',
    'zustand' => 'Zustand',
];

// Karriere-Initiativbewerbung may include a CV/cover letter upload.
$attachment = validate_upload('lebenslauf', ['pdf', 'doc', 'docx'], 8 * 1024 * 1024);

$skip = ['website', 'form_name', 'ref'];
$lines = [];

// If this submission arrived via a tippgeber's referral link, resolve who referred them.
$refInfo = null;
if (!empty($_POST['ref'])) {
    $refInfo = decode_ref_token($_POST['ref']);
    if ($refInfo) {
        $lines[] = 'Empfohlen von (Tippgeber): ' . trim($refInfo['vorname'] . ' ' . $refInfo['nachname']) . ' (' . $refInfo['email'] . ')';
    }
}

foreach ($_POST as $key => $value) {
    if (in_array($key, $skip, true)) continue;
    $value = is_array($value) ? implode(', ', $value) : trim((string) $value);
    if ($value === '') continue;
    $label = $labels[$key] ?? ucfirst(str_replace('_', ' ', $key));
    $lines[] = $label . ': ' . $value;
}

if (empty($lines)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'empty_submission']);
    exit;
}

if ($attachment) {
    $lines[] = 'Anhang: ' . $attachment['name'];
}

$body = implode("\n", $lines) . "\n\n---\nGesendet über das Formular \"" . $formName . "\" auf putz-realestate.at";
$subject = $formName;

$sent = send_mail_maybe_with_attachment(
    $to,
    $subject,
    $body,
    'PUTZ Real Estate Website <noreply@putz-realestate.at>',
    $email,
    $attachment
);

// For new Tippgeber registrations, also send the tipster their personal referral link.
if ($sent && $formName === 'Tippgeber-Registrierung') {
    $vorname = trim($_POST['vorname'] ?? '');
    $nachname = trim($_POST['nachname'] ?? '');
    $token = build_ref_token($vorname, $nachname, $email);
    $link = SITE_URL . '/empfehlung.html?ref=' . $token;

    $confirmBody = "Hallo " . $vorname . ",\n\n"
        . "vielen Dank für Ihre Registrierung als Tippgeber bei PUTZ Real Estate!\n\n"
        . "Hier ist Ihr persönlicher Empfehlungslink:\n" . $link . "\n\n"
        . "Kennen Sie jemanden, der eine Immobilie verkaufen möchte? Senden Sie ihm einfach diesen Link. "
        . "Meldet sich die Person darüber bei uns, wissen wir automatisch, dass der Tipp von Ihnen kommt.\n\n"
        . "Kommt es zu einem erfolgreichen Verkauf, erhalten Sie 20 % unserer Provision.\n\n"
        . "Herzliche Grüße\nIhr PUTZ Real Estate Team";

    nachricht_senden($email, 'Ihr persönlicher Tippgeber-Link', $confirmBody);

    // Persist the registration so it can be viewed in the password-protected admin list.
    $tippgeber = [
        'timestamp' => date('Y-m-d H:i'),
        'vorname' => $vorname,
        'nachname' => $nachname,
        'firma' => trim($_POST['firma'] ?? ''),
        'telefon' => trim($_POST['nummer'] ?? ''),
        'email' => $email,
        'iban' => trim($_POST['iban'] ?? ''),
    ];
    datensatz_anhaengen('tippgeber.json', $tippgeber);

    try {
        require_once __DIR__ . '/tippgeber-db.php';
        tg_tippgeber_sichern(tg_db(), $tippgeber);
    } catch (Throwable $e) {
        error_log('Tippgeber nicht in die Datenbank geschrieben: ' . $e->getMessage());
    }
}

// For Karriere-Initiativbewerbungen, also send the applicant a confirmation.
if ($sent && $formName === 'Karriere – Initiativbewerbung') {
    $vorname = trim($_POST['vorname'] ?? '');

    $confirmBody = "Hallo " . $vorname . ",\n\n"
        . "Danke für dein Interesse, mit uns zusammenzuarbeiten. Wir freuen uns schon, deine Unterlagen zu durchstöbern. "
        . "Wir melden uns zeitnah bei dir und hoffen, dass auch du bald mit uns gemeinsam Immobilien vom Markt PUTZEN kannst!\n\n"
        . "Herzliche Grüße\nDein PUTZ Real Estate Team";

    nachricht_senden($email, 'Deine Bewerbung ist bei uns angekommen', $confirmBody);
}

// For Kostenlose Immobilienbewertung requests, also send a confirmation.
if ($sent && $formName === 'Kostenlose Immobilienbewertung') {
    $vorname = trim($_POST['vorname'] ?? '');

    $confirmBody = "Hallo " . $vorname . ",\n\n"
        . "vielen Dank für Ihre Anfrage zur kostenlosen Immobilienbewertung bei PUTZ Real Estate! Wir haben Ihre Angaben erhalten "
        . "und melden uns innerhalb von 48 Stunden mit einer ersten, unverbindlichen Werteinschätzung Ihrer Immobilie bei Ihnen.\n\n"
        . "Herzliche Grüße\nIhr PUTZ Real Estate Team";

    nachricht_senden($email, 'Ihre Anfrage zur kostenlosen Immobilienbewertung ist bei uns angekommen', $confirmBody);
}

// For Suchkunde-Anfragen, also send a confirmation and store the search profile.
if ($sent && $formName === 'Suchkunde-Anfrage') {
    $vorname = trim($_POST['vorname'] ?? '');
    $nachname = trim($_POST['nachname'] ?? '');

    $confirmBody = "Hallo " . $vorname . ",\n\n"
        . "danke für dein Suchprofil bei PUTZ Real Estate! Wir haben deine Wünsche gespeichert und melden uns bei dir, "
        . "sobald eine passende Immobilie — auch aus unserem Off-Market-Bestand — verfügbar ist.\n\n"
        . "Herzliche Grüße\nDein PUTZ Real Estate Team";

    nachricht_senden($email, 'Dein Suchprofil ist bei uns angekommen', $confirmBody);

    // Persist the search profile in its own list (separate from the Tippgeber list).
    datensatz_anhaengen('suchkunden.json', [
        'timestamp' => date('Y-m-d H:i'),
        'vorname' => $vorname,
        'nachname' => $nachname,
        'email' => $email,
        'telefon' => trim($_POST['nummer'] ?? ''),
        'gebiet' => trim($_POST['gebiet'] ?? ''),
        'umkreis' => trim($_POST['umkreis'] ?? ''),
        'immobilientyp' => trim($_POST['immobilientyp'] ?? ''),
        'zimmer' => trim($_POST['zimmer'] ?? ''),
        'groesse' => trim($_POST['groesse'] ?? ''),
        'sonderwuensche' => trim($_POST['sonderwuensche'] ?? ''),
    ]);
}

// ------------------------------------------------------------
//  Empfehlung festhalten
//
//  Kam die Absendung ueber den Link eines Tippgebers, wird sie hier
//  gespeichert. Bisher stand der Tippgeber nur im Text der E-Mail —
//  damit war nicht nachvollziehbar, wer wie viele Empfehlungen
//  gebracht hat, und das Tippgeber-Dashboard haette nichts anzuzeigen.
// ------------------------------------------------------------
if ($sent && $refInfo) {
    $empfehlung = [
        // Kennung zum spaeteren Aendern des Status im Admin-Bereich.
        'id'        => bin2hex(random_bytes(8)),
        'zeitpunkt' => date('Y-m-d H:i'),
        'formular'  => $formName,

        // Wer empfohlen hat
        'tippgeber_vorname'  => $refInfo['vorname'],
        'tippgeber_nachname' => $refInfo['nachname'],
        'tippgeber_email'    => $refInfo['email'],

        // Wen er empfohlen hat. Der Tippgeber sieht spaeter nur den Namen,
        // die Kontaktdaten bleiben dem Admin-Bereich vorbehalten.
        'name'    => trim(($_POST['vorname'] ?? '') . ' ' . ($_POST['nachname'] ?? '')),
        'email'   => $email,
        'telefon' => trim($_POST['telefon'] ?? $_POST['nummer'] ?? ''),
        'objekt'  => trim($_POST['objekt'] ?? ''),

        // Vom Admin-Bereich gepflegt
        'status'            => 'eingegangen',
        'provision'         => null,
        'provision_bezahlt' => false,
        'notiz'             => '',
    ];

    // Zuerst in die JSON-Datei — der bewaehrte Weg, dient als Sicherung.
    datensatz_anhaengen('empfehlungen.json', $empfehlung);

    // Dann in die Datenbank, auf der die Tippgeber-App arbeitet.
    // In try/catch, damit ein Datenbankproblem die Anfrage nicht gefaehrdet.
    try {
        require_once __DIR__ . '/tippgeber-db.php';
        tg_empfehlung_anlegen(tg_db(), $empfehlung);
    } catch (Throwable $e) {
        error_log('Empfehlung nicht in die Datenbank geschrieben: ' . $e->getMessage());
    }
}

// Objektanfragen zusaetzlich in Justimmo eintragen.
//
// Das geschieht bewusst NACH dem Mailversand und in einem try/catch:
// Die E-Mail ist der verlaessliche Weg, ueber den die Anfrage ankommt.
// Sollte Justimmo nicht erreichbar sein oder die Zugangsdaten fehlen,
// darf das die Anfrage niemals scheitern lassen — es wird nur protokolliert.
if ($sent && $formName === 'Immobilien-Anfrage') {
    $objektId = trim($_POST['justimmo_objekt_id'] ?? '');
    $konfigPfad = __DIR__ . '/justimmo-config.php';

    if ($objektId !== '' && is_file($konfigPfad)) {
        try {
            require_once __DIR__ . '/justimmo-lib.php';
            [$jiOk, $jiHinweis] = ji_anfrageSenden($objektId, [
                'vorname'   => trim($_POST['vorname'] ?? ''),
                'nachname'  => trim($_POST['nachname'] ?? ''),
                'email'     => $email,
                'telefon'   => trim($_POST['telefon'] ?? ''),
                'nachricht' => trim($_POST['nachricht'] ?? ''),
            ], require $konfigPfad);

            if (!$jiOk) {
                error_log('Justimmo-Anfrage fehlgeschlagen (Objekt ' . $objektId . '): ' . $jiHinweis);
            }
        } catch (Throwable $e) {
            error_log('Justimmo-Anfrage abgebrochen: ' . $e->getMessage());
        }
    }
}

if ($sent) {
    echo json_encode(['ok' => true]);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'send_failed']);
}
