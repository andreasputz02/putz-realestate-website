<?php
// ============================================================
//  VORLAGE — bitte kopieren als:  justimmo-config.php
//
//  Die echte Datei justimmo-config.php ist bewusst von Git
//  ausgeschlossen, damit die Zugangsdaten nie im Repository landen.
//
//  Wo finde ich die Daten?
//  In Justimmo unter  Einstellungen → Schnittstellen → API-Export.
//  Dort stehen Benutzername und Passwort für die API.
// ============================================================

return [
    // Zugangsdaten aus dem Justimmo-Backend
    'benutzer'  => 'HIER_API_BENUTZERNAME',
    'passwort'  => 'HIER_API_PASSWORT',

    // Wie lange die abgerufenen Objekte zwischengespeichert werden (Sekunden).
    // 900 = 15 Minuten. Solange wird die Justimmo-API nicht erneut befragt,
    // damit die Seite schnell bleibt und die Schnittstelle nicht unnoetig belastet wird.
    'cache_sekunden' => 900,

    // Wie viele Objekte maximal geladen werden (API erlaubt max. 100).
    'anzahl' => 100,
];
