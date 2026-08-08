<?php
// ============================================================
//  Empfehlungs-Token
//
//  Der persoenliche Empfehlungslink eines Tippgebers traegt seinen
//  Namen und seine E-Mail-Adresse im Klartext (base64) plus eine
//  Signatur. Die Signatur verhindert, dass jemand einen Link auf
//  einen fremden Namen faelscht.
//
//  Liegt in einer eigenen Datei, weil sowohl send-mail.php (beim
//  Auswerten eingehender Formulare) als auch der Tippgeber-Bereich
//  (beim Anzeigen des Links) dieselbe Berechnung brauchen. Vorher
//  stand sie nur in send-mail.php — eine zweite Fassung woanders
//  waere frueher oder spaeter auseinandergelaufen.
// ============================================================

// Nur serverseitig verwendet, gelangt nie zum Browser.
if (!defined('REF_SECRET')) {
    define('REF_SECRET', 'putz-real-estate-referral-8f3c1a9d-2026');
}
if (!defined('SITE_URL')) {
    define('SITE_URL', 'https://putz-realestate.at');
}

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
    // hash_equals vergleicht in konstanter Zeit — sonst liesse sich die
    // Signatur zeichenweise erraten.
    if (!hash_equals($expected, $sig)) return null;
    $fields = explode('|', base64url_decode($payload));
    if (count($fields) !== 3) return null;
    return ['vorname' => $fields[0], 'nachname' => $fields[1], 'email' => $fields[2]];
}
