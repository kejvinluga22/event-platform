/* service-worker.js
 * Caches the app shell so the scanner UI loads instantly even on isolated
 * networks. API requests are network-first (and fall back to the IndexedDB
 * offline queue handled in the React layer).
 */

const CACHE = 'smart-event-shell-v2'
const SHELL = ['/', '/index.html', '/manifest.json']

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)))
  self.skipWaiting()
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
    )
  )
  self.clients.claim()
})

self.addEventListener('fetch', (event) => {
  const { request } = event
  const url = new URL(request.url)

  // Never cache API calls — they must be fresh or handled by the offline queue.
  if (url.pathname.includes('/api/')) {
    event.respondWith(fetch(request).catch(() => new Response(JSON.stringify({ offline: true }), {
      headers: { 'Content-Type': 'application/json' },
      status: 503,
    })))
    return
  }

  // App shell: cache-first with background refresh.
  event.respondWith(
    caches.match(request).then((cached) => {
      const network = fetch(request)
        .then((res) => {
          const copy = res.clone()
          caches.open(CACHE).then((c) => c.put(request, copy))
          return res
        })
        .catch(() => cached)
      return cached || network
    })
  )
})
