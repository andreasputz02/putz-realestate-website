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

// Liefert SITE_URL — wird in den Benachrichtigungen gebraucht.
require_once __DIR__ . '/ref-token.php';

const TG_DB_DATEI = __DIR__ . '/data/tippgeber.sqlite';

// Anteil des Tippgebers an unserer Provision, in Prozent.
// Steht so auch auf tippgeber.html — beide muessen zusammenpassen.
const TG_ANTEIL_STANDARD = 20.0;

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

    // Spalten, die spaeter dazugekommen sind. CREATE TABLE oben laeuft nur
    // beim allerersten Mal — eine bereits bestehende Datenbank bekaeme sie
    // sonst nie. ALTER TABLE nur, wenn die Spalte wirklich fehlt.
    tg_spalte_ergaenzen($db, 'empfehlungen', 'verkaufspreis',   'REAL');
    tg_spalte_ergaenzen($db, 'empfehlungen', 'provisionssatz',  'REAL');
    tg_spalte_ergaenzen($db, 'empfehlungen', 'anteil_prozent',  'REAL');
    tg_spalte_ergaenzen($db, 'empfehlungen', 'objekt_id',       "TEXT NOT NULL DEFAULT ''");
    tg_spalte_ergaenzen($db, 'empfehlungen', 'objekt_titel',    "TEXT NOT NULL DEFAULT ''");
    tg_spalte_ergaenzen($db, 'empfehlungen', 'objekt_seite',    "TEXT NOT NULL DEFAULT ''");
    // Merker, damit dieselbe Nachricht nicht zweimal rausgeht.
    tg_spalte_ergaenzen($db, 'empfehlungen', 'gemeldet_online',   'INTEGER NOT NULL DEFAULT 0');
    tg_spalte_ergaenzen($db, 'empfehlungen', 'gemeldet_verkauft', 'INTEGER NOT NULL DEFAULT 0');

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

/** Ergaenzt eine Spalte nur dann, wenn sie noch nicht existiert. */
function tg_spalte_ergaenzen(PDO $db, string $tabelle, string $spalte, string $definition): void
{
    $vorhanden = array_column($db->query("PRAGMA table_info($tabelle)")->fetchAll(), 'name');
    if (!in_array($spalte, $vorhanden, true)) {
        $db->exec("ALTER TABLE $tabelle ADD COLUMN $spalte $definition");
    }
}

/**
 * Rechnet den Anteil des Tippgebers aus:
 *
 *   Verkaufspreis x Maklerprovision x Anteil des Tippgebers
 *
 * So steht es auf der Tippgeber-Seite: 20 % unserer Provision,
 * unsere Provision 3-6 % vom Verkaufspreis.
 *
 * Rueckgabe null, solange nicht beide Werte vorliegen — dann wird
 * bewusst kein Betrag angezeigt statt einer erfundenen Zahl.
 */
function tg_provision_rechnen($verkaufspreis, $satz, $anteil = null): ?float
{
    $verkaufspreis = (float)$verkaufspreis;
    $satz = (float)$satz;
    if ($verkaufspreis <= 0 || $satz <= 0) return null;
    $anteil = (float)($anteil ?: TG_ANTEIL_STANDARD);
    return round($verkaufspreis * ($satz / 100) * ($anteil / 100), 2);
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

/**
 * Benachrichtigt den Tippgeber — nur an den beiden Wendepunkten:
 * sein Tipp ist als Objekt online, und der Verkauf ist durch.
 *
 * Jede Nachricht geht hoechstens einmal raus; dafuer sorgen die
 * Merkerspalten. Ein Fehlschlag wird nur protokolliert und darf das
 * Speichern im Admin-Bereich nie aufhalten.
 */
function tg_benachrichtigen(PDO $db, int $empfehlungId, string $anlass): void
{
    $spalte = $anlass === 'online' ? 'gemeldet_online' : 'gemeldet_verkauft';

    $s = $db->prepare("
        SELECT e.*, t.vorname, t.email AS tg_email
        FROM empfehlungen e JOIN tippgeber t ON t.id = e.tippgeber_id
        WHERE e.id = ?
    ");
    $s->execute([$empfehlungId]);
    $e = $s->fetch();

    if (!$e || (int)$e[$spalte] === 1 || trim($e['tg_email']) === '') return;

    $anrede = trim($e['vorname']) !== '' ? 'Hallo ' . $e['vorname'] . ',' : 'Hallo,';
    $wer = trim($e['name']) !== '' ? $e['name'] : 'deine Empfehlung';

    if ($anlass === 'online') {
        $betreff = 'Deine Empfehlung ist online';
        $text = "$anrede\n\n"
              . "gute Nachrichten: Das Objekt zu deiner Empfehlung ($wer) ist jetzt online.\n\n"
              . ($e['objekt_titel'] !== '' ? $e['objekt_titel'] . "\n" : '')
              . ($e['objekt_seite']  !== '' ? $e['objekt_seite']  . "\n" : '')
              . "\nDen aktuellen Stand siehst du jederzeit in deinem Tippgeber-Bereich:\n"
              . SITE_URL . "/tippgeber-login.php\n\n"
              . "Danke, dass du an uns gedacht hast!\n\nLiebe Grüße\nPUTZ Real Estate";
    } else {
        $betreff = 'Deine Empfehlung war erfolgreich';
        $betrag = $e['provision'] !== null
            ? 'Deine Prämie beträgt € ' . number_format((float)$e['provision'], 2, ',', '.') . ".\n"
            : '';
        $text = "$anrede\n\n"
              . "deine Empfehlung ($wer) hat zum Verkauf geführt — herzlichen Dank!\n\n"
              . $betrag
              . "\nAlle Einzelheiten findest du in deinem Tippgeber-Bereich:\n"
              . SITE_URL . "/tippgeber-login.php\n\n"
              . "Wir melden uns wegen der Auszahlung bei dir.\n\nLiebe Grüße\nPUTZ Real Estate";
    }

    $kopf = [
        'From: PUTZ Real Estate <noreply@putz-realestate.at>',
        'Reply-To: office@putzrealestate.at',
        'Content-Type: text/plain; charset=UTF-8',
        'MIME-Version: 1.0',
    ];

    $ok = @mail($e['tg_email'], '=?UTF-8?B?' . base64_encode($betreff) . '?=', $text, implode("\r\n", $kopf));
    if ($ok) {
        $db->prepare("UPDATE empfehlungen SET $spalte = 1 WHERE id = ?")->execute([$empfehlungId]);
    } else {
        error_log("Tippgeber-Benachrichtigung ($anlass) fehlgeschlagen fuer Empfehlung $empfehlungId");
    }
}
