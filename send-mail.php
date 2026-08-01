<?php
header('Content-Type: application/json; charset=utf-8');

// Server-side only secret used to sign referral tokens. Never exposed to the client.
define('REF_SECRET', 'putz-real-estate-referral-8f3c1a9d-2026');
define('SITE_URL', 'https://putz-realestate.at');

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function base64url_decode($data) {
    $pad = strlen($data) % 4;
    if ($pad) $data .= str_repeat('=', 4 - $pad);
    return base64_decode(strtr($data, '-_', '+/'));
}
function build_ref_token($vorname, $nachname, $email) {
    $payload = base64url_encode($vorname . '|' . $nachname . '|' . $email);
    $sig = substr(hash_hmac('sha256', $payload, REF_SECRET), 0, 16);
    return $payload . '.' . $sig;
}
function decode_ref_token($token) {
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) return null;
    [$payload, $sig] = $parts;
    $expected = substr(hash_hmac('sha256', $payload, REF_SECRET), 0, 16);
    if (!hash_equals($expected, $sig)) return null;
    $fields = explode('|', base64url_decode($payload));
    if (count($fields) !== 3) return null;
    return ['vorname' => $fields[0], 'nachname' => $fields[1], 'email' => $fields[2]];
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
];

// Karriere-Initiativbewerbung may include a CV/cover letter upload.
$attachment = validate_upload('lebenslauf', ['pdf', 'doc', 'docx'], 8 * 1024 * 1024);

$skip = ['website', 'form_name', 'ref'];
$lines = [];

// If this submission arrived via a tippgeber's referral link, resolve who referred them.
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

    $confirmHeaders = [];
    $confirmHeaders[] = 'From: PUTZ Real Estate <office@putzrealestate.at>';
    $confirmHeaders[] = 'Content-Type: text/plain; charset=UTF-8';
    $confirmHeaders[] = 'MIME-Version: 1.0';
    $confirmSubject = '=?UTF-8?B?' . base64_encode('Ihr persönlicher Tippgeber-Link') . '?=';
    mail($email, $confirmSubject, $confirmBody, implode("\r\n", $confirmHeaders));

    // Persist the registration so it can be viewed in the password-protected admin list.
    $dataDir = __DIR__ . '/data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }
    $dataFile = $dataDir . '/tippgeber.json';
    $entry = [
        'timestamp' => date('Y-m-d H:i'),
        'vorname' => $vorname,
        'nachname' => $nachname,
        'firma' => trim($_POST['firma'] ?? ''),
        'telefon' => trim($_POST['nummer'] ?? ''),
        'email' => $email,
        'iban' => trim($_POST['iban'] ?? ''),
    ];
    $fp = fopen($dataFile, 'c+');
    if ($fp) {
        flock($fp, LOCK_EX);
        $existing = stream_get_contents($fp);
        $records = json_decode($existing, true);
        if (!is_array($records)) $records = [];
        $records[] = $entry;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

// For Karriere-Initiativbewerbungen, also send the applicant a confirmation.
if ($sent && $formName === 'Karriere – Initiativbewerbung') {
    $vorname = trim($_POST['vorname'] ?? '');

    $confirmBody = "Hallo " . $vorname . ",\n\n"
        . "Danke für dein Interesse, mit uns zusammenzuarbeiten. Wir freuen uns schon, deine Unterlagen zu durchstöbern. "
        . "Wir melden uns zeitnah bei dir und hoffen, dass auch du bald mit uns gemeinsam Immobilien vom Markt PUTZEN kannst!\n\n"
        . "Herzliche Grüße\nDein PUTZ Real Estate Team";

    $confirmHeaders = [];
    $confirmHeaders[] = 'From: PUTZ Real Estate <office@putzrealestate.at>';
    $confirmHeaders[] = 'Content-Type: text/plain; charset=UTF-8';
    $confirmHeaders[] = 'MIME-Version: 1.0';
    $confirmSubject = '=?UTF-8?B?' . base64_encode('Deine Bewerbung ist bei uns angekommen') . '?=';
    mail($email, $confirmSubject, $confirmBody, implode("\r\n", $confirmHeaders));
}

if ($sent) {
    echo json_encode(['ok' => true]);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'send_failed']);
}
