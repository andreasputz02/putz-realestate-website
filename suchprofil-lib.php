<?php
// ============================================================
//  Suchprofile: speichern, bestaetigen, abgleichen
//
//  Wer ueber "Suchagent anlegen" oder die Seite /suchkunde seine
//  Wuensche schickt, bekommt hier einen Datensatz. Erst nach dem Klick
//  auf den Bestaetigungslink (doppelte Zustimmung) verschickt
//  suchprofil-versand.php taeglich neue Treffer.
//
//  SQLite wie bei der Tippgeber-App: kein eigener Datenbankserver
//  noetig, die Datei liegt in data/ und ist dort von aussen gesperrt.
// ============================================================

require_once __DIR__ . '/ref-token.php';      // SITE_URL
require_once __DIR__ . '/mail-versand.php';   // nachricht_senden()

const SP_DB_DATEI = __DIR__ . '/data/suchprofile.sqlite';

/** Verbindung zur Datenbank; legt die Tabellen beim ersten Aufruf an. */
function sp_db(): PDO
{
    static $db = null;
    if ($db instanceof PDO) return $db;

    $ordner = dirname(SP_DB_DATEI);
    if (!is_dir($ordner)) @mkdir($ordner, 0755, true);

    $db = new PDO('sqlite:' . SP_DB_DATEI);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('CREATE TABLE IF NOT EXISTS profile (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        token       TEXT NOT NULL UNIQUE,
        vorname     TEXT NOT NULL DEFAULT "",
        nachname    TEXT NOT NULL DEFAULT "",
        email       TEXT NOT NULL UNIQUE,
        telefon     TEXT NOT NULL DEFAULT "",
        art         TEXT NOT NULL DEFAULT "",      -- kauf | miete | ""
        objektart   TEXT NOT NULL DEFAULT "",      -- Wohnung | Haus | Grundstück | ""
        orte        TEXT NOT NULL DEFAULT "",      -- Freitext: PLZ, Bezirk, Ort
        umkreis     INTEGER NOT NULL DEFAULT 0,    -- km, 0 = egal
        lat         REAL,
        lng         REAL,
        zimmer_ab   REAL,
        flaeche_ab  REAL,
        preis_bis   REAL,
        wunsch      TEXT NOT NULL DEFAULT "",
        erstellt    TEXT NOT NULL,
        bestaetigt  TEXT,
        abgemeldet  TEXT,
        letzte_mail TEXT
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS gesendet (
        profil_id  INTEGER NOT NULL,
        objekt_id  TEXT NOT NULL,
        zeitpunkt  TEXT NOT NULL,
        PRIMARY KEY (profil_id, objekt_id)
    )');
    return $db;
}

/** "1.500,50", "ab 80 m²", "500.000 €" -> Zahl oder null. */
function sp_zahl($roh): ?float
{
    $t = preg_replace('/[^\d,.]/', '', (string)$roh);
    if ($t === '') return null;
    // Punkte sind Tausendertrenner, das Komma ist das Dezimalzeichen.
    $t = str_replace('.', '', $t);
    $t = str_replace(',', '.', $t);
    return is_numeric($t) ? (float)$t : null;
}

/**
 * Nimmt die Felder aus beiden Formularen entgegen (Suchagent-Fenster und
 * Seite /suchkunde) und legt daraus ein Profil an. Gibt es die Adresse
 * schon, werden die Wuensche aktualisiert.
 *
 * Rueckgabe: das Profil als Array oder null, wenn die Daten nicht reichen.
 */
function sp_aufnehmen(array $d): ?array
{
    $email = trim((string)($d['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return null;

    $art = strtolower(trim((string)($d['art'] ?? '')));
    $art = str_contains($art, 'miet') ? 'miete' : (str_contains($art, 'kauf') ? 'kauf' : '');

    $objektart = trim((string)($d['typ'] ?? $d['immobilientyp'] ?? ''));
    if (preg_match('/beides|egal|sonstiges/i', $objektart)) $objektart = '';

    $felder = [
        'vorname'    => trim((string)($d['vorname'] ?? '')),
        'nachname'   => trim((string)($d['nachname'] ?? '')),
        'telefon'    => trim((string)($d['telefon'] ?? $d['nummer'] ?? '')),
        'art'        => $art,
        'objektart'  => $objektart,
        'orte'       => trim((string)($d['lage'] ?? $d['gebiet'] ?? '')),
        'umkreis'    => (int)round(sp_zahl($d['umkreis'] ?? 0) ?? 0),
        'zimmer_ab'  => sp_zahl($d['zimmer'] ?? null),
        'flaeche_ab' => sp_zahl($d['groesse'] ?? $d['flaeche'] ?? null),
        'preis_bis'  => sp_zahl($d['preis'] ?? null),
        'wunsch'     => trim((string)($d['sonderwuensche'] ?? $d['details'] ?? $d['nachricht'] ?? '')),
    ];

    $db = sp_db();
    $vorhanden = $db->prepare('SELECT * FROM profile WHERE email = ?');
    $vorhanden->execute([$email]);
    $alt = $vorhanden->fetch(PDO::FETCH_ASSOC);

    if ($alt) {
        $felder['abgemeldet'] = null;   // meldet sich jemand neu an, zaehlt der neue Wunsch
        $satz = [];
        foreach ($felder as $k => $v) $satz[] = $k . ' = :' . $k;
        $sql = $db->prepare('UPDATE profile SET ' . implode(', ', $satz) . ' WHERE id = :id');
        $sql->execute($felder + ['id' => $alt['id']]);
        $profil = array_merge($alt, $felder);
        $profil['id'] = $alt['id'];
    } else {
        $felder['email'] = $email;
        $felder['token'] = bin2hex(random_bytes(16));
        $felder['erstellt'] = date('c');
        $spalten = array_keys($felder);
        $sql = $db->prepare('INSERT INTO profile (' . implode(', ', $spalten) . ') VALUES (:' . implode(', :', $spalten) . ')');
        $sql->execute($felder);
        $felder['id'] = (int)$db->lastInsertId();
        $felder['bestaetigt'] = null;
        $profil = $felder;
    }

    // Umkreissuche braucht Koordinaten. Einmal nachschlagen, dann merken.
    if ($profil['umkreis'] > 0 && empty($profil['lat']) && $profil['orte'] !== '') {
        $punkt = sp_geokodieren($profil['orte']);
        if ($punkt) {
            $db->prepare('UPDATE profile SET lat = ?, lng = ? WHERE id = ?')
               ->execute([$punkt[0], $punkt[1], $profil['id']]);
            $profil['lat'] = $punkt[0];
            $profil['lng'] = $punkt[1];
        }
    }
    return $profil;
}

/** Schlaegt einen Ort bei OpenStreetMap nach. Rueckgabe [lat, lng] oder null. */
function sp_geokodieren(string $ort): ?array
{
    $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=at&q='
         . rawurlencode($ort);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_USERAGENT      => 'PUTZ-Real-Estate-Website (office@putzrealestate.at)',
    ]);
    $roh = curl_exec($ch);
    curl_close($ch);
    $d = json_decode((string)$roh, true);
    if (!is_array($d) || !isset($d[0]['lat'])) return null;
    return [(float)$d[0]['lat'], (float)$d[0]['lon']];
}

/** Entfernung zweier Punkte in Metern. */
function sp_entfernung(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371000.0;
    $p1 = deg2rad($lat1);
    $p2 = deg2rad($lat2);
    $dp = deg2rad($lat2 - $lat1);
    $dl = deg2rad($lng2 - $lng1);
    $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    return 2 * $r * asin(min(1, sqrt($a)));
}

/**
 * Passt ein Objekt zu einem Profil?
 *
 * Fehlt am Objekt eine Angabe, schliesst das das Objekt NICHT aus —
 * genau wie in der Suchmaske auf der Website. Lieber ein Treffer zu
 * viel als ein verpasstes Objekt.
 */
function sp_passt(array $p, array $o): bool
{
    if (!empty($o['verkauft'])) return false;

    if (!empty($p['art']) && !empty($o['type']) && $o['type'] !== $p['art']) return false;

    if (!empty($p['objektart'])) {
        $gesucht = mb_strtolower($p['objektart']);
        $haben = mb_strtolower(($o['objektart'] ?? '') . ' ' . ($o['title'] ?? ''));
        // "Grundstück" steht oft nur im Titel, "Haus" auch in "Reihenhaus".
        if (!str_contains($haben, mb_substr($gesucht, 0, 5))) return false;
    }

    if ($p['preis_bis'] !== null && isset($o['preisWert']) && $o['preisWert'] !== null
        && $o['preisWert'] > $p['preis_bis']) return false;

    if ($p['flaeche_ab'] !== null && isset($o['flaecheWert']) && $o['flaecheWert'] !== null
        && $o['flaecheWert'] < $p['flaeche_ab']) return false;

    if ($p['zimmer_ab'] !== null && isset($o['zimmerWert']) && $o['zimmerWert'] !== null
        && $o['zimmerWert'] < $p['zimmer_ab']) return false;

    // Ort: entweder im Umkreis oder der Text kommt in Adresse/PLZ vor.
    $ort = trim((string)$p['orte']);
    if ($ort !== '') {
        $imUmkreis = false;
        if ((int)$p['umkreis'] > 0 && $p['lat'] !== null && !empty($o['lat']) && !empty($o['lng'])) {
            $imUmkreis = sp_entfernung((float)$p['lat'], (float)$p['lng'], (float)$o['lat'], (float)$o['lng'])
                       <= (int)$p['umkreis'] * 1000;
        }
        if (!$imUmkreis) {
            $haben = mb_strtolower(($o['location'] ?? '') . ' ' . ($o['plz'] ?? '') . ' ' . ($o['title'] ?? ''));
            $treffer = false;
            foreach (preg_split('/[,;\/]+| und /i', $ort) as $teil) {
                $teil = trim(mb_strtolower($teil));
                if ($teil !== '' && mb_strlen($teil) >= 3 && str_contains($haben, $teil)) { $treffer = true; break; }
            }
            if (!$treffer) return false;
        }
    }
    return true;
}

/** Schickt die Bestaetigungsmail (doppelte Zustimmung). */
function sp_bestaetigung_senden(array $p): bool
{
    $link = SITE_URL . '/suchprofil?t=' . $p['token'] . '&a=ja';
    $stop = SITE_URL . '/suchprofil?t=' . $p['token'] . '&a=stop';
    $name = trim(($p['vorname'] ?? '') . ' ' . ($p['nachname'] ?? ''));

    $text = ($name !== '' ? "Hallo $name," : 'Hallo,') . "\n\n"
        . "danke für deine Suchanfrage. Ein Klick fehlt noch, dann melden wir dich,\n"
        . "sobald eine passende Immobilie bei uns hereinkommt:\n\n"
        . $link . "\n\n"
        . "Das ist deine Suche:\n" . sp_wunsch_text($p) . "\n"
        . "Wir schicken höchstens eine Mail pro Tag und nur, wenn es wirklich etwas Neues gibt.\n"
        . "Abmelden kannst du dich jederzeit mit einem Klick: " . $stop . "\n\n"
        . "Herzliche Grüße\nAndreas Putz\nPUTZ Real Estate GmbH\n"
        . "0664 3500662 · office@putzrealestate.at\n";

    return nachricht_senden($p['email'], 'Bitte bestätige deine Immobiliensuche', $text);
}

/** Die Wuensche als Liste, fuer Mails und die Uebersicht. */
function sp_wunsch_text(array $p): string
{
    $zeilen = [];
    if (!empty($p['art']))       $zeilen[] = '- ' . ($p['art'] === 'miete' ? 'Miete' : 'Kauf');
    if (!empty($p['objektart'])) $zeilen[] = '- ' . $p['objektart'];
    if (!empty($p['orte']))      $zeilen[] = '- Gebiet: ' . $p['orte'] . ((int)$p['umkreis'] > 0 ? ' (+' . (int)$p['umkreis'] . ' km)' : '');
    if ($p['zimmer_ab']  !== null) $zeilen[] = '- ab ' . rtrim(rtrim(number_format((float)$p['zimmer_ab'], 1, ',', '.'), '0'), ',') . ' Zimmer';
    if ($p['flaeche_ab'] !== null) $zeilen[] = '- ab ' . number_format((float)$p['flaeche_ab'], 0, ',', '.') . ' m²';
    if ($p['preis_bis']  !== null) $zeilen[] = '- bis ' . number_format((float)$p['preis_bis'], 0, ',', '.') . ' €';
    if (!$zeilen) $zeilen[] = '- keine Einschränkung angegeben';
    return implode("\n", $zeilen) . "\n";
}

/** Profil zu einem Token holen. */
function sp_profil(string $token): ?array
{
    $sql = sp_db()->prepare('SELECT * FROM profile WHERE token = ?');
    $sql->execute([$token]);
    $p = $sql->fetch(PDO::FETCH_ASSOC);
    return $p ?: null;
}

function sp_bestaetigen(string $token): ?array
{
    $p = sp_profil($token);
    if (!$p) return null;
    sp_db()->prepare('UPDATE profile SET bestaetigt = ?, abgemeldet = NULL WHERE id = ?')
           ->execute([date('c'), $p['id']]);
    $p['bestaetigt'] = date('c');
    $p['abgemeldet'] = null;
    return $p;
}

function sp_abmelden(string $token): ?array
{
    $p = sp_profil($token);
    if (!$p) return null;
    sp_db()->prepare('UPDATE profile SET abgemeldet = ? WHERE id = ?')->execute([date('c'), $p['id']]);
    $p['abgemeldet'] = date('c');
    return $p;
}
