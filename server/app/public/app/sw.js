// Service worker: app installabile e consultabile anche senza rete.
// - Interfaccia (HTML/JS/CSS/icone): dalla cache, aggiornata in background.
// - Dati (/api GET): dalla rete; senza rete, l'ultima copia salvata.
// - Scritture: mai in cache (le carte create offline le gestisce app.js con una coda).
const VERSION = 'cl-v1';
const SHELL = ['/app/', '/app/index.html', '/app/app.js', '/app/style.css', '/app/manifest.webmanifest', '/app/icon-192.png', '/app/icon-512.png'];
const SHELL_CACHE = `${VERSION}-shell`;
const DATA_CACHE = `${VERSION}-data`;

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(SHELL_CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys()
    .then((keys) => Promise.all(keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k))))
    .then(() => self.clients.claim()));
});

// Al logout l'app chiede di cancellare i dati salvati.
self.addEventListener('message', (e) => {
  if (e.data === 'clear-data') e.waitUntil(caches.delete(DATA_CACHE));
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  const url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== location.origin) return;

  if (url.pathname.startsWith('/api/')) {
    // Niente cache per file, export e foto (grandi o personali).
    if (/\.csv$|\/photos\//.test(url.pathname)) return;
    e.respondWith(fetch(req).then((res) => {
      if (res.ok) {
        const copy = res.clone();
        caches.open(DATA_CACHE).then((c) => c.put(req, copy));
      }
      return res;
    }).catch(() => caches.match(req).then((hit) => hit || new Response(
      JSON.stringify({ error: 'Sei offline e questi dati non sono ancora stati salvati sul dispositivo' }),
      { status: 503, headers: { 'Content-Type': 'application/json' } }))));
    return;
  }

  if (url.pathname.startsWith('/app/')) {
    // Stale-while-revalidate per l'interfaccia.
    e.respondWith(caches.open(SHELL_CACHE).then((c) => c.match(req, { ignoreSearch: true }).then((hit) => {
      const net = fetch(req).then((res) => { if (res.ok) c.put(req, res.clone()); return res; }).catch(() => hit || c.match('/app/'));
      return hit || net;
    })));
  }
});
