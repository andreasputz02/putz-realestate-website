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
];

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

$body = implode("\n", $lines) . "\n\n---\nGesendet über das Formular \"" . $formName . "\" auf putz-realestate.at";
$subject = $formName;

$headers = [];
$headers[] = 'From: PUTZ Real Estate Website <noreply@putz-realestate.at>';
$headers[] = 'Reply-To: ' . $email;
$headers[] = 'Content-Type: text/plain; charset=UTF-8';
$headers[] = 'MIME-Version: 1.0';

$encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
$sent = mail($to, $encodedSubject, $body, implode("\r\n", $headers));

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

if ($sent) {
    echo json_encode(['ok' => true]);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'send_failed']);
}
