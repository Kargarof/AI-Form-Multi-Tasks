/* AI Form Multi Tasks · service worker
 * - Pantalla principal y datos: primero la red; sin conexión, la última copia guardada.
 * - Los cambios hechos sin conexión los guarda app.js en el dispositivo y los envía después (api.php?action=sync).
 * - Estilos, scripts, iconos y fuentes: se sirven desde caché y se actualizan en segundo plano.
 * - Login, logout, alta y cualquier petición que cambie datos: siempre a la red, nunca se guardan.
 * La publicación automática (GitHub Actions) cambia VERSION en cada despliegue.
 */
const VERSION = 'v4';
const STATIC_CACHE = `aiform-mt-static-${VERSION}`;
const DATA_CACHE = 'aiform-mt-data';
const PRECACHE = [
  'offline.html',
  'manifest.webmanifest',
  'assets/icon.svg',
  'assets/icons/icon-192.png',
  'assets/icons/icon-512.png',
  'assets/icons/apple-touch-icon.png',
];

const scopeURL = new URL(self.registration.scope);
const inScope = (url) => url.origin === scopeURL.origin && url.pathname.startsWith(scopeURL.pathname);
const rel = (url) => url.pathname.slice(scopeURL.pathname.length);

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then((cache) => cache.addAll(PRECACHE.map((p) => new URL(p, scopeURL).href)))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys
      .filter((k) => k.startsWith('aiform-mt-static-') && k !== STATIC_CACHE)
      .map((k) => caches.delete(k)));
    await self.clients.claim();
  })());
});

self.addEventListener('message', (event) => {
  // Al cerrar sesión se borra la copia de los datos de este dispositivo.
  if (event.data === 'clear-data') event.waitUntil(caches.delete(DATA_CACHE));
  if (event.data === 'skip-waiting') self.skipWaiting();
});

function markOffline(response) {
  const headers = new Headers(response.headers);
  headers.set('X-Offline-Copy', '1');
  return new Response(response.body, { status: response.status, statusText: response.statusText, headers });
}

async function networkFirst(request) {
  const cache = await caches.open(DATA_CACHE);
  try {
    const response = await fetch(request);
    if (response.ok && !response.redirected) cache.put(request, response.clone());
    return response;
  } catch (err) {
    const cached = await cache.match(request);
    if (cached) return markOffline(cached);
    if (request.mode === 'navigate') {
      return (await caches.match(new URL('offline.html', scopeURL).href)) || Response.error();
    }
    return new Response(JSON.stringify({ error: 'Sin conexión. Revisa tu internet.' }), {
      status: 503, headers: { 'Content-Type': 'application/json; charset=utf-8', 'X-Offline-Copy': '1' },
    });
  }
}

async function staleWhileRevalidate(request) {
  const cache = await caches.open(STATIC_CACHE);
  const cached = await cache.match(request);
  const update = fetch(request).then((response) => {
    if (response.ok || response.type === 'opaque') cache.put(request, response.clone());
    return response;
  }).catch(() => cached);
  return cached || update;
}

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);

  // Fuentes de Google: caché primero.
  if (url.hostname === 'fonts.googleapis.com' || url.hostname === 'fonts.gstatic.com') {
    event.respondWith(staleWhileRevalidate(request));
    return;
  }
  if (!inScope(url)) return;

  const path = rel(url);
  if (/^(welcome|oauth)\.php/.test(path)) return; // siempre a la red

  if (path.startsWith('api.php')) {
    const action = url.searchParams.get('action');
    if (['projects', 'tasks', 'me'].includes(action)) event.respondWith(networkFirst(request));
    return;
  }
  if (request.mode === 'navigate') {
    event.respondWith(networkFirst(request));
    return;
  }
  if (path.startsWith('assets/') || path === 'manifest.webmanifest' || path === 'offline.html') {
    event.respondWith(staleWhileRevalidate(request));
  }
});
