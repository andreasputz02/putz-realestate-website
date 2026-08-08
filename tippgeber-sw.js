// ============================================================
//  Service Worker des Tippgeber-Bereichs
//
//  Bewusst sehr zurueckhaltend: Zwischengespeichert wird NUR das
//  Aussehen (Stylesheet, Schriften, Symbole). Die Seiten selbst
//  kommen immer frisch vom Server.
//
//  Warum: tippgeber-app.php zeigt personenbezogene Daten und den
//  jeweils aktuellen Stand. Eine zwischengespeicherte Fassung
//  koennte veraltete Zahlen zeigen — oder, schlimmer, auf einem
//  geteilten Geraet die Daten des zuvor angemeldeten Tippgebers.
// ============================================================

const CACHE = 'tippgeber-v1';

// Nur Unveraenderliches — alles mit Versionsnummer im Namen.
const AUSSEHEN = [
  '/css/style.css?v=154',
  '/css/fonts.css?v=3',
  '/assets/img/logo.png',
  '/assets/img/app-symbol-192.png',
  '/assets/img/app-symbol-512.png',
];

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE)
      // Einzeln hinzufuegen: faellt eine Datei aus, scheitert nicht alles.
      .then((c) => Promise.allSettled(AUSSEHEN.map((u) => c.add(u))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((namen) => Promise.all(namen.filter((n) => n !== CACHE).map((n) => caches.delete(n))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);

  // Fremde Server und alles ausser einfachem Abruf: nicht anfassen.
  if (e.request.method !== 'GET' || url.origin !== self.location.origin) return;

  // Seiten, PHP und Daten immer vom Server holen — niemals aus dem Speicher.
  if (url.pathname.endsWith('.php') || e.request.mode === 'navigate') return;

  // Aussehen: erst Speicher, sonst Netz.
  e.respondWith(
    caches.match(e.request).then((treffer) => treffer || fetch(e.request))
  );
});
