import axios from 'axios'

// Axios instance — talks to the PHP REST API. In dev, /api is proxied by Vite.
// In production, set VITE_API_URL to the backend's public URL (e.g. Railway service URL + /api).
const API_BASE = import.meta.env.VITE_API_URL || '/api'

const api = axios.create({
  baseURL: API_BASE,
  withCredentials: true,
  headers: { 'Content-Type': 'application/json' },
})

// The backend embeds root-relative paths like "/api/registrations/5/ticket.pdf"
// in some JSON responses (download/view links). Frontend and backend are
// separate Railway services with different origins in production, so those
// paths must be resolved against the API's own origin — not the page's
// current origin — or the browser requests a route that doesn't exist there.
// In dev, API_BASE is just "/api" (Vite-proxied, same origin), so this is a no-op.
export const API_ORIGIN = API_BASE.replace(/\/api\/?$/, '')

// Attach the JWT (also sent as HttpOnly cookie, this is the header fallback).
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('access_token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

// ---------------------------------------------------------------------------
// Offline scan queue (IndexedDB) — used by the PWA when connectivity drops.
// ---------------------------------------------------------------------------
const DB_NAME = 'smart-event-offline'
const STORE = 'scan_queue'

function openDb() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, 1)
    req.onupgradeneeded = () => {
      const db = req.result
      if (!db.objectStoreNames.contains(STORE)) {
        db.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true })
      }
    }
    req.onsuccess = () => resolve(req.result)
    req.onerror = () => reject(req.error)
  })
}

export async function queueOfflineScan(entry) {
  const db = await openDb()
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE, 'readwrite')
    tx.objectStore(STORE).add({ ...entry, scanned_at: new Date().toISOString() })
    tx.oncomplete = () => resolve(true)
    tx.onerror = () => reject(tx.error)
  })
}

export async function getOfflineScans() {
  const db = await openDb()
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE, 'readonly')
    const req = tx.objectStore(STORE).getAll()
    req.onsuccess = () => resolve(req.result)
    req.onerror = () => reject(req.error)
  })
}

export async function clearOfflineScans() {
  const db = await openDb()
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE, 'readwrite')
    tx.objectStore(STORE).clear()
    tx.oncomplete = () => resolve(true)
    tx.onerror = () => reject(tx.error)
  })
}

// Flush any queued offline scans to the server in one batch.
export async function syncOfflineScans() {
  const scans = await getOfflineScans()
  if (!scans.length) return { processed: [] }
  const { data } = await api.post('/scan/sync', { scans })
  await clearOfflineScans()
  return data.data
}

// ---------------------------------------------------------------------------
// Authenticated file downloads/views (CSV/Excel/PDF export, badge, ticket,
// certificate). These endpoints require a Bearer token, which a plain <a
// href> can't send — and the HttpOnly cookie fallback doesn't reliably work
// either, since it's set via a cross-site XHR response (frontend and backend
// are different Railway domains), which browsers increasingly treat as a
// third-party cookie and block. Fetching through `api` (which does attach
// the header) and handing the browser a blob URL sidesteps both problems.
// ---------------------------------------------------------------------------
async function fetchBlobUrl(path) {
  const res = await api.get(path, { responseType: 'blob' })
  return URL.createObjectURL(res.data)
}

/** Open a file (PDF ticket/badge/certificate) in a new tab. */
export async function openFile(path) {
  const url = await fetchBlobUrl(path)
  window.open(url, '_blank')
  setTimeout(() => URL.revokeObjectURL(url), 60_000)
}

/** Download a file (CSV/Excel export) with the given filename. */
export async function downloadFile(path, filename) {
  const url = await fetchBlobUrl(path)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  a.remove()
  URL.revokeObjectURL(url)
}

export default api
