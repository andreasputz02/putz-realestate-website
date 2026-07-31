<?php
header('Content-Type: application/json; charset=utf-8');

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

$skip = ['website', 'form_name'];
$lines = [];
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
$subject = 'Neue Anfrage – ' . $formName;

$headers = [];
$headers[] = 'From: PUTZ Real Estate Website <noreply@putz-realestate.at>';
$headers[] = 'Reply-To: ' . $email;
$headers[] = 'Content-Type: text/plain; charset=UTF-8';
$headers[] = 'MIME-Version: 1.0';

$encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
$sent = mail($to, $encodedSubject, $body, implode("\r\n", $headers));

if ($sent) {
    echo json_encode(['ok' => true]);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'send_failed']);
}
