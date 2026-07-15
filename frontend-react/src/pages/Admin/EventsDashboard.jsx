import { useEffect, useState, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import api, { downloadFile, openFile } from '../../api/client'
import { useAuth } from '../../contexts/AuthContext'
import Button from '../../components/Common/Button'
import AnalyticsChart from '../../components/Dashboard/AnalyticsChart'

const STATUS_FLOW = {
  draft: ['published'],
  published: ['live', 'paused', 'archived'],
  live: ['paused', 'archived'],
  paused: ['live', 'archived'],
  archived: ['published'], // allow restoring archived back to published
}

const TABS = [
  { key: 'all',       label: 'All' },
  { key: 'draft',     label: 'Drafts' },
  { key: 'published', label: 'Published' },
  { key: 'live',      label: 'Live' },
  { key: 'archived',  label: 'Archived' },
]

/**
 * EventsDashboard — the organizer control center landing view. Lists every
 * event with status filters, quick actions, share-link copy, soft delete,
 * a per-event analytics drawer, and a top-of-page stats banner.
 */
export default function EventsDashboard() {
  const { isAdmin } = useAuth()
  const navigate = useNavigate()

  const [events, setEvents] = useState([])
  const [stats, setStats] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [selected, setSelected] = useState(null)
  const [analytics, setAnalytics] = useState(null)
  const [busyId, setBusyId] = useState(null)
  const [tab, setTab] = useState('all')
  const [query, setQuery] = useState('')
  const [toast, setToast] = useState('')

  const flash = (msg) => {
    setToast(msg)
    setTimeout(() => setToast(''), 2400)
  }

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [evResp, statsResp] = await Promise.all([
        api.get('/events', { params: tab === 'all' ? {} : { status: tab } }),
        api.get('/events/stats'),
      ])
      setEvents(evResp.data.data.events || [])
      setStats(statsResp.data.data)
    } catch (err) {
      setError(err?.response?.data?.error || 'Failed to load events')
    } finally {
      setLoading(false)
    }
  }, [tab])

  useEffect(() => { load() }, [load])

  const openAnalytics = async (ev) => {
    setSelected(ev)
    setAnalytics(null)
    try {
      const { data } = await api.get(`/events/${ev.id}/analytics`)
      setAnalytics(data.data)
    } catch {
      setAnalytics({ total_registrations: 0, checked_in: 0, capacity: ev.capacity, hourly_velocity: [] })
    }
  }

  const changeStatus = async (ev, status) => {
    setBusyId(ev.id)
    try {
      await api.patch(`/events/${ev.id}/status`, { status })
      await load()
    } finally {
      setBusyId(null)
    }
  }

  const cloneEvent = async (ev) => {
    setBusyId(ev.id)
    try {
      const { data } = await api.post(`/events/${ev.id}/clone`)
      await load()
      navigate(`/events/${data.data.id}/edit`)
    } finally {
      setBusyId(null)
    }
  }

  const deleteEvent = async (ev) => {
    if (!confirm(`Delete "${ev.title}"?\n\nThis hides it from the dashboard but keeps all registration data. You can restore it later from the Archived tab.`)) {
      return
    }
    setBusyId(ev.id)
    try {
      await api.delete(`/events/${ev.id}`)
      flash('Event deleted')
      await load()
    } catch (err) {
      alert(err?.response?.data?.error || 'Delete failed')
    } finally {
      setBusyId(null)
    }
  }

  const resendTicket = async (regId) => {
    setBusyId(`r${regId}`)
    try {
      await api.post(`/registrations/${regId}/resend`)
      if (selected) openAnalytics(selected)
      flash('Ticket queued for resend')
    } catch (err) {
      alert(err?.response?.data?.error || 'Resend failed')
    } finally {
      setBusyId(null)
    }
  }

  const publicLink = (slug) => `${window.location.origin}/e/${slug}`
  const scannerLink = (id) => `${window.location.origin}/events/${id}/scan`

  const copyShareLink = async (ev) => {
    const url = publicLink(ev.slug)
    try {
      await navigator.clipboard.writeText(url)
      flash('Share link copied to clipboard')
    } catch {
      window.prompt('Copy this link:', url)
    }
  }

  const copyScannerLink = async (ev) => {
    const url = scannerLink(ev.id)
    try {
      await navigator.clipboard.writeText(url)
      flash('Scanner link copied — share with your staff')
    } catch {
      window.prompt('Copy this link:', url)
    }
  }

  // Client-side title search on top of the server-side status filter.
  const filteredEvents = query.trim()
    ? events.filter((e) => e.title.toLowerCase().includes(query.toLowerCase()))
    : events

  if (loading) return <div className="spinner" />
  if (error) return <div className="error">{error}</div>

  return (
    <>
      {toast && (
        <div
          style={{
            position: 'fixed', bottom: 24, right: 24, zIndex: 1000,
            background: '#15803d', color: '#fff', padding: '10px 18px',
            borderRadius: 10, fontSize: 14, boxShadow: '0 4px 14px rgba(0,0,0,.18)',
          }}
        >{toast}</div>
      )}

      {/* Top stats banner */}
      {stats && (
        <div className="grid cols-4" style={{ marginBottom: 22 }}>
          <Stat label="Total events"    value={stats.totals.events}        sub={`${stats.totals.active} active`} />
          <Stat label="Registrations"   value={stats.totals.registrations} />
          <Stat label="Checked in"      value={stats.totals.checked_in}    sub={`${stats.totals.attendance_rate}% attendance`} />
          <Stat label="Archived"        value={stats.totals.archived} />
        </div>
      )}

      <div className="row between" style={{ marginBottom: 14, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h1 style={{ fontFamily: 'var(--font-display)', fontSize: 26 }}>Events</h1>
        </div>
        {isAdmin() && (
          <Button onClick={() => navigate('/events/new')}>+ Create event</Button>
        )}
      </div>

      {/* Filter tabs + search */}
      <div className="row between" style={{ marginBottom: 14, flexWrap: 'wrap', gap: 10 }}>
        <div className="row" style={{ gap: 6, flexWrap: 'wrap' }}>
          {TABS.map((t) => (
            <button
              key={t.key}
              className={`btn ${tab === t.key ? 'primary' : 'ghost'} sm`}
              onClick={() => setTab(t.key)}
            >{t.label}</button>
          ))}
        </div>
        <input
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Search events…"
          style={{ width: 240, padding: '8px 12px', borderRadius: 8, border: '1px solid var(--line)' }}
        />
      </div>

      <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
        <table className="data">
          <thead>
            <tr>
              <th>Title</th>
              <th>Venue</th>
              <th>Capacity</th>
              <th>Status</th>
              <th style={{ textAlign: 'right' }}>Actions</th>
            </tr>
          </thead>
          <tbody>
            {filteredEvents.length === 0 && (
              <tr><td colSpan={5} className="muted" style={{ textAlign: 'center', padding: 36 }}>
                {query ? 'No events match your search.' :
                 tab === 'archived' ? 'No archived events.' :
                 'No events yet. Create your first one to get started.'}
              </td></tr>
            )}
            {filteredEvents.map((ev) => (
              <tr key={ev.id}>
                <td>
                  <div className="name" style={{ fontWeight: 600 }}>{ev.title}</div>
                  <a
                    className="muted"
                    style={{ fontSize: 12 }}
                    href={publicLink(ev.slug)}
                    target="_blank"
                    rel="noreferrer"
                  >/e/{ev.slug}</a>
                </td>
                <td className="muted">{ev.venue || '—'}</td>
                <td>{ev.capacity || '∞'}</td>
                <td><span className={`pill ${ev.status}`}>{ev.status}</span></td>
                <td>
                  <div className="row" style={{ justifyContent: 'flex-end', gap: 6, flexWrap: 'wrap' }}>
                    <button className="btn ghost sm" onClick={() => openAnalytics(ev)}>Stats</button>

                    {['published', 'live'].includes(ev.status) && (
                      <button className="btn ghost sm" onClick={() => copyShareLink(ev)} title="Copy public registration link">
                        🔗 Share
                      </button>
                    )}

                    <button className="btn ghost sm" onClick={() => navigate(`/events/${ev.id}/scan`)} title="Open this event's scanner">
                      📷 Scan
                    </button>
                    <button className="btn ghost sm" onClick={() => copyScannerLink(ev)} title="Copy scanner link for staff">
                      Link
                    </button>

                    {isAdmin() && (
                      <>
                        <button className="btn ghost sm" onClick={() => navigate(`/events/${ev.id}/edit`)}>Edit</button>
                        <button className="btn ghost sm" disabled={busyId === ev.id} onClick={() => cloneEvent(ev)}>Clone</button>
                        {(STATUS_FLOW[ev.status] || []).map((next) => (
                          <button
                            key={next}
                            className="btn ghost sm"
                            disabled={busyId === ev.id}
                            onClick={() => changeStatus(ev, next)}
                          >→ {next}</button>
                        ))}
                        <button
                          className="btn ghost sm"
                          style={{ color: '#b91c1c', borderColor: '#fecaca' }}
                          disabled={busyId === ev.id}
                          onClick={() => deleteEvent(ev)}
                          title="Soft-delete (restorable)"
                        >🗑</button>
                      </>
                    )}
                    <button type="button" className="btn ghost sm" onClick={() => downloadFile(`/events/${ev.id}/export`, `registrations_event_${ev.id}.csv`)}>CSV</button>
                    <button type="button" className="btn ghost sm" onClick={() => downloadFile(`/events/${ev.id}/export/xlsx`, `registrations_event_${ev.id}.xlsx`)}>Excel</button>
                    <button type="button" className="btn ghost sm" onClick={() => downloadFile(`/events/${ev.id}/export/pdf`, `registrations_event_${ev.id}.pdf`)}>PDF</button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {selected && (
        <div className="card mt" style={{ marginTop: 22 }}>
          <div className="row between" style={{ marginBottom: 16 }}>
            <h2 style={{ fontFamily: 'var(--font-display)', fontSize: 20 }}>
              {selected.title} — live telemetry
            </h2>
            <button className="btn ghost sm" onClick={() => setSelected(null)}>Close</button>
          </div>
          {analytics ? (
            <>
              <AnalyticsChart analytics={analytics} />

              {analytics.recent_registrations && (
                <div style={{ marginTop: 26 }}>
                  <h3 style={{ fontSize: 16, marginBottom: 12 }}>Recent registrations</h3>
                  {analytics.recent_registrations.length === 0 ? (
                    <div className="muted" style={{ padding: 18, textAlign: 'center' }}>No registrations yet.</div>
                  ) : (
                    <table className="data">
                      <thead>
                        <tr>
                          <th>Attendee</th>
                          <th>Ticket</th>
                          <th>Status</th>
                          <th>Check-in</th>
                          <th style={{ textAlign: 'right' }}>Actions</th>
                        </tr>
                      </thead>
                      <tbody>
                        {analytics.recent_registrations.map((r) => (
                          <tr key={r.id}>
                            <td>
                              <div style={{ fontWeight: 600 }}>{r.attendee_name}</div>
                              <div className="muted" style={{ fontSize: 12 }}>{r.attendee_email}</div>
                            </td>
                            <td>{r.access_level}</td>
                            <td><span className={`pill ${r.status}`}>{r.status}</span></td>
                            <td><span className={`pill ${r.checkin_status}`}>{r.checkin_status}</span></td>
                            <td>
                              <div className="row" style={{ justifyContent: 'flex-end', gap: 6, flexWrap: 'wrap' }}>
                                <button type="button" className="btn ghost sm" onClick={() => openFile(`/registrations/${r.id}/ticket.pdf`)}>Ticket</button>
                                <button type="button" className="btn ghost sm" onClick={() => openFile(`/registrations/${r.id}/badge`)}>Badge</button>
                                <button type="button" className="btn ghost sm" onClick={() => openFile(`/registrations/${r.id}/certificate`)}>Cert</button>
                                {isAdmin() && r.status === 'confirmed' && (
                                  <button
                                    className="btn ghost sm"
                                    onClick={() => resendTicket(r.id)}
                                    disabled={busyId === `r${r.id}`}
                                  >Resend</button>
                                )}
                              </div>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  )}
                </div>
              )}
            </>
          ) : <div className="spinner" />}
        </div>
      )}
    </>
  )
}

function Stat({ label, value, sub }) {
  return (
    <div className="card stat" style={{ padding: 14 }}>
      <div className="label muted" style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: 1 }}>{label}</div>
      <div className="value" style={{ fontFamily: 'var(--font-display)', fontSize: 28, fontWeight: 800, lineHeight: 1.1 }}>{value}</div>
      {sub && <div className="muted" style={{ fontSize: 12, marginTop: 2 }}>{sub}</div>}
    </div>
  )
}
