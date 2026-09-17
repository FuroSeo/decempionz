const CACHE = 'decempionz-v5.16.4';
const SHELL_ASSETS = [
  '/',
  '/index.html',
  '/og-image.png',
  '/icon-192.png',
  '/icon-512.png',
  '/manifest.json'
];
const NAV_TIMEOUT_MS = 6000;
const STATIC_ASSET_RE = /\.(?:css|js|png|jpe?g|gif|webp|svg|ico|woff2?|ttf)$/i;

self.addEventListener('install', event => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE);
    await Promise.all(
      SHELL_ASSETS.map(url =>
        cache.add(new Request(url, { cache: 'reload' })).catch(() => null)
      )
    );
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', event => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(
      keys
        .filter(key => key.startsWith('decempionz-') && key !== CACHE)
        .map(key => caches.delete(key))
    );
    await self.clients.claim();
  })());
});

function fetchWithTimeout(request, timeoutMs) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('network timeout')), timeoutMs);
    fetch(request).then(
      response => {
        clearTimeout(timer);
        resolve(response);
      },
      error => {
        clearTimeout(timer);
        reject(error);
      }
    );
  });
}

function isDynamicRequest(url) {
  const path = url.pathname.toLowerCase();
  return path.endsWith('.php') ||
    path.startsWith('/daily-scores/') ||
    path.includes('/hall-of-fame') ||
    path.includes('/challenge-') ||
    path.includes('/duel-');
}

async function networkFirstNavigation(request) {
  try {
    const response = await fetchWithTimeout(request, NAV_TIMEOUT_MS);

    if (response && response.ok) {
      const url = new URL(request.url);
      if (url.pathname === '/' || url.pathname === '/index.html') {
        const cache = await caches.open(CACHE);
        cache.put('/index.html', response.clone()).catch(() => {});
      }
    }

    return response;
  } catch (error) {
    const cached = await caches.match(request, { ignoreSearch: true });
    if (cached) return cached;

    const requestUrl = new URL(request.url);
    if (requestUrl.pathname === '/' || requestUrl.pathname === '/index.html') {
      const shell = await caches.match('/index.html');
      if (shell) return shell;
    }

    return new Response(
      '<!doctype html><html lang="it"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Decempionz offline</title><body style="font-family:system-ui;background:#07090f;color:#e8edf5;padding:32px"><h1>Decempionz</h1><p>Connessione non disponibile. Riprova quando sei online.</p></body></html>',
      { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
    );
  }
}

async function staleWhileRevalidate(request, event) {
  const cache = await caches.open(CACHE);
  const cached = await cache.match(request);

  const networkPromise = fetch(request)
    .then(response => {
      if (response && response.ok && response.type === 'basic') {
        cache.put(request, response.clone()).catch(() => {});
      }
      return response;
    })
    .catch(() => null);

  if (cached) {
    event.waitUntil(networkPromise.then(() => undefined));
    return cached;
  }

  const response = await networkPromise;
  if (response) return response;
  throw new Error('asset unavailable');
}

self.addEventListener('fetch', event => {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);

  // Leave third-party resources, the service worker itself and dynamic endpoints
  // entirely to the browser/network. They must never be served from the app cache.
  if (url.origin !== self.location.origin) return;
  if (url.pathname === '/sw.js') return;
  if (isDynamicRequest(url)) return;

  if (request.mode === 'navigate') {
    event.respondWith(networkFirstNavigation(request));
    return;
  }

  if (STATIC_ASSET_RE.test(url.pathname)) {
    event.respondWith(staleWhileRevalidate(request, event));
  }
});
