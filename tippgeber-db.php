<?php
// ============================================================
//  Datenbank der Tippgeber-App
//
//  SQLite, weil es keinen eigenen Datenbankserver braucht und die
//  Datei in data/ liegt — dort sperrt .htaccess jeden direkten
//  Zugriff von aussen aus.
//
//  Die bisherigen JSON-Dateien werden beim ersten Aufruf einmalig
//  uebernommen und danach nicht mehr gelesen. Sie bleiben als
//  Sicherung liegen.
// ============================================================

const TG_DB_DATEI = __DIR__ . '/data/tippgeber.sqlite';

/**
 * Liefert die Datenbankverbindung und legt Tabellen sowie
 * Alt-Datenuebernahme beim ersten Aufruf an.
 */
function tg_db(): PDO
{
    static $db = null;
    if ($db instanceof PDO) return $db;

    $ordner = dirname(TG_DB_DATEI);
    if (!is_dir($ordner)) mkdir($ordner, 0755, true);

    $neu = !is_file(TG_DB_DATEI);

    $db = new PDO('sqlite:' . TG_DB_DATEI);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Schreibzugriffe kurz warten lassen statt sofort abbrechen,
    // falls zwei Anfragen gleichzeitig eintreffen.
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA foreign_keys = ON');

    tg_schema_anlegen($db);
    if ($neu) tg_altdaten_uebernehmen($db);

    return $db;
}

function tg_schema_anlegen(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS tippgeber (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            vorname   TEXT NOT NULL DEFAULT '',
            nachname  TEXT NOT NULL DEFAULT '',
            firma     TEXT NOT NULL DEFAULT '',
            telefon   TEXT NOT NULL DEFAULT '',
            email     TEXT NOT NULL UNIQUE,
            iban      TEXT NOT NULL DEFAULT '',
            angelegt  TEXT NOT NULL
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS empfehlungen (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            tippgeber_id      INTEGER REFERENCES tippgeber(id) ON DELETE SET NULL,
            tippgeber_email   TEXT NOT NULL DEFAULT '',
            zeitpunkt         TEXT NOT NULL,
            formular          TEXT NOT NULL DEFAULT '',
            name              TEXT NOT NULL DEFAULT '',
            email             TEXT NOT NULL DEFAULT '',
            telefon           TEXT NOT NULL DEFAULT '',
            objekt            TEXT NOT NULL DEFAULT '',
            status            TEXT NOT NULL DEFAULT 'eingegangen',
            provision         REAL,
            provision_bezahlt INTEGER NOT NULL DEFAULT 0,
            notiz             TEXT NOT NULL DEFAULT ''
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_empf_tippgeber ON empfehlungen(tippgeber_id)");

    // Anmeldelinks: gespeichert wird nur der HASH des Tokens — wer die
    // Datenbank liest, kann sich damit trotzdem nicht anmelden.
    $db->exec("
        CREATE TABLE IF NOT EXISTS anmeldelinks (
            token_hash   TEXT PRIMARY KEY,
            tippgeber_id INTEGER NOT NULL REFERENCES tippgeber(id) ON DELETE CASCADE,
            erstellt     TEXT NOT NULL,
            gueltig_bis  TEXT NOT NULL,
            benutzt      INTEGER NOT NULL DEFAULT 0
        )
    ");
}

/**
 * Uebernimmt die bisherigen JSON-Listen einmalig in die Datenbank.
 */
function tg_altdaten_uebernehmen(PDO $db): void
{
    $lesen = function (string $datei): array {
        $p = __DIR__ . '/data/' . $datei;
        if (!is_file($p)) return [];
        $d = json_decode((string)file_get_contents($p), true);
        return is_array($d) ? $d : [];
    };

    foreach ($lesen('tippgeber.json') as $t) {
        $email = trim($t['email'] ?? '');
        if ($email === '') continue;
        $stmt = $db->prepare("
            INSERT OR IGNORE INTO tippgeber (vorname, nachname, firma, telefon, email, iban, angelegt)
            VALUES (?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $t['vorname'] ?? '', $t['nachname'] ?? '', $t['firma'] ?? '',
            $t['telefon'] ?? '', $email, $t['iban'] ?? '',
            $t['timestamp'] ?? date('Y-m-d H:i'),
        ]);
    }

    foreach ($lesen('empfehlungen.json') as $e) {
        tg_empfehlung_anlegen($db, $e);
    }
}

/**
 * Traegt eine Empfehlung ein und verknuepft sie ueber die E-Mail-Adresse
 * mit dem Tippgeber, falls dieser registriert ist.
 */
function tg_empfehlung_anlegen(PDO $db, array $e): void
{
    $tgEmail = trim($e['tippgeber_email'] ?? '');
    $tgId = null;
    if ($tgEmail !== '') {
        $s = $db->prepare("SELECT id FROM tippgeber WHERE email = ?");
        $s->execute([$tgEmail]);
        $tgId = $s->fetchColumn() ?: null;
    }

    $stmt = $db->prepare("
        INSERT INTO empfehlungen
            (tippgeber_id, tippgeber_email, zeitpunkt, formular, name, email, telefon, objekt, status, provision, provision_bezahlt, notiz)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([
        $tgId,
        $tgEmail,
        $e['zeitpunkt'] ?? date('Y-m-d H:i'),
        $e['formular'] ?? '',
        $e['name'] ?? '',
        $e['email'] ?? '',
        $e['telefon'] ?? '',
        $e['objekt'] ?? '',
        $e['status'] ?? 'eingegangen',
        $e['provision'] ?? null,
        !empty($e['provision_bezahlt']) ? 1 : 0,
        $e['notiz'] ?? '',
    ]);
}

/** Legt einen Tippgeber an oder aktualisiert ihn anhand der E-Mail. */
function tg_tippgeber_sichern(PDO $db, array $t): void
{
    $email = trim($t['email'] ?? '');
    if ($email === '') return;

    $stmt = $db->prepare("
        INSERT INTO tippgeber (vorname, nachname, firma, telefon, email, iban, angelegt)
        VALUES (:vorname, :nachname, :firma, :telefon, :email, :iban, :angelegt)
        ON CONFLICT(email) DO UPDATE SET
            vorname  = excluded.vorname,
            nachname = excluded.nachname,
            firma    = excluded.firma,
            telefon  = excluded.telefon,
            iban     = excluded.iban
    ");
    $stmt->execute([
        ':vorname'  => $t['vorname'] ?? '',
        ':nachname' => $t['nachname'] ?? '',
        ':firma'    => $t['firma'] ?? '',
        ':telefon'  => $t['telefon'] ?? '',
        ':email'    => $email,
        ':iban'     => $t['iban'] ?? '',
        ':angelegt' => $t['timestamp'] ?? date('Y-m-d H:i'),
    ]);

    // Empfehlungen, die vor der Registrierung eintrafen, nachtraeglich zuordnen.
    $db->prepare("
        UPDATE empfehlungen SET tippgeber_id = (SELECT id FROM tippgeber WHERE email = ?)
        WHERE tippgeber_id IS NULL AND tippgeber_email = ?
    ")->execute([$email, $email]);
}

/** Die Beschriftungen der Status in der Reihenfolge des Ablaufs. */
function tg_status_liste(): array
{
    return [
        'eingegangen'    => 'Eingegangen',
        'in_bearbeitung' => 'In Bearbeitung',
        'termin'         => 'Termin vereinbart',
        'verkauft'       => 'Erfolgreich vermittelt',
        'abgelehnt'      => 'Nicht zustande gekommen',
    ];
}
