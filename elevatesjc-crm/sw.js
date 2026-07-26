/**
 * Minimal service worker: caches only genuinely static assets (CSS/JS/icons)
 * so the installed app shell loads instantly and works on a flaky connection.
 * Everything else — every *.php page, api/*, auth/*, manifest.php, uploads —
 * always goes straight to the network. This is a CRM: pages carry a live
 * per-session CSRF token and API calls are live business data, so caching
 * either would risk serving stale tokens or stale contacts/deals/invoices.
 *
 * Bump CACHE_NAME whenever css/styles.css or js/app.js change so installed
 * apps pick up the update instead of serving a stale cached copy forever.
 */
const CACHE_NAME = 'elevatesjc-crm-shell-v1';
const STATIC_ASSET_PATTERN = /\/(css\/styles\.css|js\/app\.js|assets\/icons\/.+\.png)(\?.*)?$/;

self.addEventListener('install', (event) => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(['css/styles.css', 'js/app.js']).catch(() => {}))
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((names) => Promise.all(
      names.filter((name) => name !== CACHE_NAME).map((name) => caches.delete(name))
    )).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  if (event.request.method !== 'GET' || !STATIC_ASSET_PATTERN.test(url.pathname)) {
    return; // not a cached asset type — let the browser handle it normally
  }

  event.respondWith(
    caches.open(CACHE_NAME).then(async (cache) => {
      const cached = await cache.match(event.request);
      const networkFetch = fetch(event.request)
        .then((response) => {
          if (response.ok) cache.put(event.request, response.clone());
          return response;
        })
        .catch(() => cached); // offline — fall back to whatever we have cached
      return cached || networkFetch;
    })
  );
});
