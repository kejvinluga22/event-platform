import { useEffect, useRef, useState, useCallback } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import api, {
  openFile,
  queueOfflineScan,
  getOfflineScans,
  syncOfflineScans,
} from '../../api/client'
import { useAuth } from '../../contexts/AuthContext'
import CameraFeed from '../../components/Scanner/CameraFeed'
import ErrorBoundary from '../../components/Common/ErrorBoundary'

/**
 * PwaScannerPortal — the on-site staff gateway. Wraps the device camera for
 * rapid QR check-ins with acoustic + haptic feedback. Runs in two modes:
 *   • Online  — instant round-trip verification against the API.
 *   • Offline — tokens are queued in IndexedDB and flushed when the network
 *               returns (POST /scan/sync).
 * Also exposes a quick lookup panel for managed walk-in arrivals.
 *
 * Mounted at two URLs:
 *   /scan                  → generic scanner (accepts QRs for any event)
 *   /events/:id/scan       → locked to a single event; shows the event name
 *                            and a live check-in counter at the top
 */
export default function PwaScannerPortal() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const { id: eventIdParam } = useParams()
  const eventId = eventIdParam ? parseInt(eventIdParam, 10) : null

  const [online, setOnline] = useState(navigator.onLine)
  const [paused, setPaused] = useState(false)
  const [result, setResult] = useState(null) // { kind:'success'|'duplicate'|'invalid', title, name, tier }
  const [pending, setPending] = useState(0)
  const [showLookup, setShowLookup] = useState(false)
  const [query, setQuery] = useState('')
  const [matches, setMatches] = useState([])
  const [eventInfo, setEventInfo] = useState(null) // { title, checked_in, total }
  const audioCtx = useRef(null)

  // ---- connectivity tracking ----
  useEffect(() => {
    const up = () => { setOnline(true); flush() }
    const down = () => setOnline(false)
    window.addEventListener('online', up)
    window.addEventListener('offline', down)
    return () => {
      window.removeEventListener('online', up)
      window.removeEventListener('offline', down)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const refreshPending = useCallback(async () => {
    try { setPending((await getOfflineScans()).length) } catch { /* ignore */ }
  }, [])

  // Fetch the event's name + check-in counter when locked to a single event.
  const refreshEventInfo = useCallback(async () => {
    if (!eventId) return
    try {
      const { data } = await api.get(`/events/${eventId}/analytics`)
      const ev = await api.get(`/events/${eventId}`)
      setEventInfo({
        title: ev.data.data.event.title,
        checked_in: data.data.checked_in,
        total: data.data.total_registrations,
        capacity: data.data.capacity,
      })
    } catch {
      setEventInfo({ title: `Event #${eventId}`, checked_in: 0, total: 0, capacity: 0 })
    }
  }, [eventId])

  useEffect(() => { refreshPending() }, [refreshPending])
  useEffect(() => { refreshEventInfo() }, [refreshEventInfo])

  // ---- feedback (audio + haptic) ----
  const beep = (kind) => {
    try {
      audioCtx.current ||= new (window.AudioContext || window.webkitAudioContext)()
      const ctx = audioCtx.current
      const osc = ctx.createOscillator()
      const gain = ctx.createGain()
      osc.connect(gain); gain.connect(ctx.destination)
      osc.frequency.value = kind === 'success' ? 880 : 220
      gain.gain.value = 0.08
      osc.start()
      osc.stop(ctx.currentTime + (kind === 'success' ? 0.12 : 0.32))
    } catch { /* audio not available */ }
    if (navigator.vibrate) navigator.vibrate(kind === 'success' ? 80 : [60, 50, 60])
  }

  const flush = useCallback(async () => {
    if (!navigator.onLine) return
    try {
      await syncOfflineScans()
      await refreshPending()
    } catch { /* try again later */ }
  }, [refreshPending])

  // ---- core scan handler ----
  const handleScan = async (token) => {
    if (!token || paused) return
    setPaused(true)

    if (!navigator.onLine) {
      await queueOfflineScan({ token, gate: 'Main' })
      await refreshPending()
      setResult({ kind: 'success', title: 'Queued offline', name: 'Will sync when online', tier: '' })
      beep('success')
      return resume()
    }

    try {
      const { data } = await api.post('/scan', { token, gate: 'Main' })
      const a = data.data.attendee || {}

      // If this scanner is locked to a specific event, reject mismatched QRs.
      if (eventId && a.event_id && Number(a.event_id) !== eventId) {
        setResult({
          kind: 'invalid',
          title: 'Wrong event',
          name: `${a.name} — registered for a different event`,
          tier: '',
        })
        beep('invalid')
        return resume()
      }

      setResult({ kind: 'success', title: 'Checked in', name: a.name, tier: a.access_level })
      beep('success')
      refreshEventInfo()
    } catch (err) {
      const body = err?.response?.data || {}
      const kind = body.scan_result === 'duplicate' ? 'duplicate' : 'invalid'
      setResult({
        kind,
        title: kind === 'duplicate' ? 'Already checked in' : 'Invalid ticket',
        name: body.attendee?.name || body.error || '',
        tier: body.attendee?.access_level || '',
      })
      beep(kind)
    }
    resume()
  }

  const resume = () => {
    setTimeout(() => { setPaused(false); setResult(null) }, 2200)
  }

  // ---- walk-in lookup ----
  const runLookup = async (e) => {
    e?.preventDefault()
    if (query.trim().length < 2) return
    try {
      const { data } = await api.get('/scan/lookup', { params: { q: query.trim() } })
      setMatches(data.data.results || [])
    } catch {
      setMatches([])
    }
  }

  return (
    <div className="scanner">
      <div className="head">
        <div>
          {eventId && eventInfo ? (
            <>
              <div style={{ fontSize: 11, opacity: 0.5, letterSpacing: 1, textTransform: 'uppercase' }}>Scanner</div>
              <div style={{ fontFamily: 'var(--font-display)', fontWeight: 800, fontSize: 18 }}>
                {eventInfo.title}
              </div>
              <div style={{ fontSize: 12, opacity: 0.7, marginTop: 2 }}>
                {eventInfo.checked_in} / {eventInfo.total} checked in
                {eventInfo.capacity > 0 ? ` · capacity ${eventInfo.capacity}` : ''}
              </div>
            </>
          ) : (
            <>
              <div style={{ fontFamily: 'var(--font-display)', fontWeight: 800, fontSize: 18 }}>
                Scan station
              </div>
              <div style={{ fontSize: 12, opacity: 0.6 }}>{user?.email}</div>
            </>
          )}
        </div>
        <div className="row" style={{ gap: 8 }}>
          <span className={`net ${online ? 'online' : 'offline'}`}>
            {online ? 'Online' : `Offline · ${pending} queued`}
          </span>
          {eventId && (
            <button className="btn ghost sm" onClick={() => navigate('/')} style={{ color: '#e8eaed', borderColor: '#2a2f36' }}>← Back</button>
          )}
          <button className="btn ghost sm" onClick={logout} style={{ color: '#e8eaed', borderColor: '#2a2f36' }}>Exit</button>
        </div>
      </div>

      {!showLookup ? (
        <>
          <ErrorBoundary>
            <CameraFeed onScan={handleScan} paused={paused} />
          </ErrorBoundary>

          {result && (
            <div className={`scan-result ${result.kind}`}>
              <div className="big">{result.title}</div>
              {result.name && <div style={{ marginTop: 6 }}>{result.name}</div>}
              {result.tier && <div className="muted" style={{ opacity: 0.7 }}>{result.tier}</div>}
            </div>
          )}

          <div className="row" style={{ justifyContent: 'center', gap: 10, padding: 16 }}>
            <button className="btn ghost sm" style={{ color: '#e8eaed', borderColor: '#2a2f36' }} onClick={() => setShowLookup(true)}>
              Walk-in lookup
            </button>
            {pending > 0 && online && (
              <button className="btn primary sm" onClick={flush}>Sync {pending} now</button>
            )}
          </div>
        </>
      ) : (
        <div style={{ padding: 20, maxWidth: 460, margin: '0 auto', width: '100%' }}>
          <form onSubmit={runLookup} className="row" style={{ gap: 8 }}>
            <input
              autoFocus
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Search name or email…"
              style={{ flex: 1, padding: '12px 14px', borderRadius: 10, border: '1px solid #2a2f36', background: '#15181d', color: '#e8eaed' }}
            />
            <button className="btn primary sm" type="submit">Search</button>
          </form>

          <div style={{ marginTop: 16 }}>
            {matches.map((m) => (
              <div key={m.id} className="scan-result" style={{ background: '#15181d', border: '1px solid #2a2f36', textAlign: 'left', padding: 16 }}>
                <div style={{ fontWeight: 600 }}>{m.attendee_name}</div>
                <div className="muted" style={{ opacity: 0.7, fontSize: 13 }}>{m.attendee_email} · {m.access_level}</div>
                <span className={`pill ${m.checkin_status}`} style={{ marginTop: 8, display: 'inline-block' }}>{m.checkin_status}</span>
                <div className="row" style={{ gap: 8, marginTop: 12 }}>
                  <button type="button" className="btn ghost sm" style={{ color: '#e8eaed', borderColor: '#2a2f36' }} onClick={() => openFile(`/registrations/${m.id}/badge`)}>Badge</button>
                  <button type="button" className="btn ghost sm" style={{ color: '#e8eaed', borderColor: '#2a2f36' }} onClick={() => openFile(`/registrations/${m.id}/certificate`)}>Certificate</button>
                </div>
              </div>
            ))}
            {query.length >= 2 && matches.length === 0 && (
              <div className="muted" style={{ textAlign: 'center', opacity: 0.6, marginTop: 20 }}>No matches</div>
            )}
          </div>

          <div className="row" style={{ justifyContent: 'center', marginTop: 18 }}>
            <button className="btn ghost sm" style={{ color: '#e8eaed', borderColor: '#2a2f36' }} onClick={() => { setShowLookup(false); setMatches([]); setQuery('') }}>
              ← Back to camera
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
