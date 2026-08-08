<?php
// ============================================================
//  Versand von Bestaetigungen an Kunden
//
//  Diese Nachrichten landeten anfangs im Spam-Ordner. Ursachen und
//  Gegenmassnahmen, der Reihe nach:
//
//  1. Umschlag-Absender (Return-Path)
//     PHPs mail() traegt sonst den Standardnutzer des Servers ein,
//     etwa u123456@srv42.hostinger.at. Der Empfaenger prueft SPF aber
//     gegen genau diese Adresse — nicht gegen das sichtbare "From".
//     Beides muss dieselbe Domain haben, sonst schlaegt die Pruefung
//     fehl. Dafuer sorgt der fuenfte Parameter "-f".
//
//  2. Message-ID und Date
//     Fehlen beide, werten das viele Filter als Hinweis auf ein
//     Skript, das massenhaft verschickt. Beide Kopfzeilen sind
//     schnell gesetzt und kosten nichts.
//
//  Was hier NICHT zu loesen ist: DKIM. Die Nachrichten muessen dafuer
//  mit einem Schluessel signiert werden, der im DNS der Domain
//  hinterlegt ist. Das geht nur im Hostinger-Panel, nicht im Code.
// ============================================================

if (!defined('MAIL_ABSENDER')) {
    // Muss zur Domain gehoeren, fuer die der Server senden darf.
    define('MAIL_ABSENDER', 'noreply@putz-realestate.at');
    define('MAIL_ABSENDER_NAME', 'PUTZ Real Estate');
    // Antworten sollen im echten Postfach landen.
    define('MAIL_ANTWORT', 'office@putzrealestate.at');
}

/**
 * Verschickt eine Nachricht als reinen Text.
 *
 * Rueckgabe: true, wenn der Server sie angenommen hat. Das sagt nichts
 * darueber, ob sie auch zugestellt wurde.
 */
function nachricht_senden(string $an, string $betreff, string $text, ?string $antwortAn = null): bool
{
    if (!filter_var($an, FILTER_VALIDATE_EMAIL)) return false;

    $domain = substr(strrchr(MAIL_ABSENDER, '@'), 1);

    $kopf = [
        'From: ' . MAIL_ABSENDER_NAME . ' <' . MAIL_ABSENDER . '>',
        'Reply-To: ' . ($antwortAn ?: MAIL_ANTWORT),
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '.' . time() . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        // Bittet Empfaenger, keine Lesebestaetigung o.ae. zu schicken.
        'Auto-Submitted: auto-generated',
    ];

    return @mail(
        $an,
        '=?UTF-8?B?' . base64_encode($betreff) . '?=',
        $text,
        implode("\r\n", $kopf),
        '-f' . MAIL_ABSENDER      // Umschlag-Absender, siehe Punkt 1 oben
    );
}
