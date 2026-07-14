import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import api from '../../api/client'
import { useAuth } from '../../contexts/AuthContext'

/**
 * ScannerEventPicker — the gate for scanner-role users. They cannot scan
 * QRs against the generic /scan endpoint because that creates a real risk
 * of accidentally checking guests in at the wrong event when multiple are
 * running. This page lists all active events and forces a deliberate
 * selection before the camera opens.
 *
 * Higher-privilege roles (super_admin, event_manager, staff) bypass this
 * entirely — they go straight to /scan.
 */
export default function ScannerEventPicker() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const [events, setEvents] = useState([])
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')

  useEffect(() => {
    const load = async () => {
      try {
        const { data } = await api.get('/events')
        // Only events that are open for check-ins make sense here.
        const active = (data.data.events || []).filter(
          (e) => ['published', 'live', 'paused'].includes(e.status)
        )
        setEvents(active)
      } catch (e) {
        setErr(e?.response?.data?.error || 'Failed to load events')
      } finally {
        setLoading(false)
      }
    }
    load()
  }, [])

  return (
    <div
      style={{
        minHeight: '100dvh',
        background: '#0e1116',
        color: '#e8eaed',
        padding: '24px 18px 36px',
      }}
    >
      <div className="row between" style={{ marginBottom: 18 }}>
        <div>
          <div style={{ fontSize: 11, opacity: 0.55, letterSpacing: 1, textTransform: 'uppercase' }}>Scanner</div>
          <div style={{ fontFamily: 'var(--font-display)', fontWeight: 800, fontSize: 22 }}>
            Select an event
          </div>
          <div style={{ fontSize: 12, opacity: 0.6, marginTop: 2 }}>{user?.email}</div>
        </div>
        <button
          className="btn ghost sm"
          onClick={logout}
          style={{ color: '#e8eaed', borderColor: '#2a2f36' }}
        >Sign out</button>
      </div>

      <div style={{ fontSize: 14, opacity: 0.75, marginBottom: 18, lineHeight: 1.5 }}>
        Tap the event you're checking guests in at. You'll only be able to scan
        QRs registered for that event — any others will be rejected as "wrong event".
      </div>

      {loading && <div className="spinner" />}
      {err && (
        <div className="error" style={{ background: '#3a1a1a', color: '#fca5a5', border: '1px solid #5b2424' }}>
          {err}
        </div>
      )}

      {!loading && !err && events.length === 0 && (
        <div
          style={{
            background: '#15181d',
            border: '1px solid #2a2f36',
            borderRadius: 12,
            padding: 24,
            textAlign: 'center',
            opacity: 0.8,
          }}
        >
          No active events available for check-in right now. Ask your event
          manager to publish an event first.
        </div>
      )}

      <div style={{ display: 'grid', gap: 12 }}>
        {events.map((ev) => (
          <button
            key={ev.id}
            onClick={() => navigate(`/events/${ev.id}/scan`)}
            style={{
              textAlign: 'left',
              background: '#15181d',
              border: '1px solid #2a2f36',
              borderRadius: 12,
              padding: '16px 18px',
              color: '#e8eaed',
              cursor: 'pointer',
              transition: 'transform .08s, border-color .08s',
            }}
            onMouseDown={(e) => e.currentTarget.style.transform = 'scale(.99)'}
            onMouseUp={(e) => e.currentTarget.style.transform = 'scale(1)'}
          >
            <div style={{ fontWeight: 700, fontSize: 16, marginBottom: 4 }}>{ev.title}</div>
            <div style={{ fontSize: 12, opacity: 0.7 }}>
              {ev.venue || 'No venue set'}
              {ev.starts_at_utc ? ' · ' + new Date(ev.starts_at_utc.replace(' ', 'T') + 'Z').toLocaleString() : ''}
            </div>
            <div style={{ marginTop: 8 }}>
              <span className={`pill ${ev.status}`}>{ev.status}</span>
            </div>
          </button>
        ))}
      </div>
    </div>
  )
}
