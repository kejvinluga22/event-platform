import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import api from '../../api/client'
import Button from '../../components/Common/Button'
import FormRenderer, { validateForm } from '../../components/FormBuilder/FormRenderer'

/**
 * RegistrationPortal — the public-facing attendee gateway. Loads a specific
 * event by its slug (no auth required), renders its schema-driven form, and
 * submits the completed data to POST /register/:slug. On success, the user
 * receives a ticket link and confirmation email with their QR badge attached.
 */
export default function RegistrationPortal() {
  const { slug } = useParams()

  const [event, setEvent] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const [attendeeName, setAttendeeName] = useState('')
  const [attendeeEmail, setAttendeeEmail] = useState('')
  const [accessLevel, setAccessLevel] = useState('general')
  const [formData, setFormData] = useState({})
  const [errors, setErrors] = useState({})

  const [submitting, setSubmitting] = useState(false)
  const [success, setSuccess] = useState(null) // { registration_id, ticket_url }

  useEffect(() => {
    const load = async () => {
      try {
        const { data } = await api.get(`/events/public/${slug}`)
        setEvent(data.data.event)
      } catch (err) {
        setError(err?.response?.data?.error || 'Event not found or unavailable')
      } finally {
        setLoading(false)
      }
    }
    load()
  }, [slug])

  const submit = async (e) => {
    e.preventDefault()
    setError('')
    setErrors({})

    // Validate built-in fields.
    const newErrors = {}
    if (!attendeeName.trim()) newErrors.attendee_name = 'Name is required'
    if (!attendeeEmail.trim() || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(attendeeEmail)) {
      newErrors.attendee_email = 'Valid email is required'
    }

    // Validate dynamic schema.
    const schemaErrors = validateForm(event.form_config || [], formData)
    Object.assign(newErrors, schemaErrors)

    if (Object.keys(newErrors).length) {
      setErrors(newErrors)
      return
    }

    setSubmitting(true)
    try {
      const { data } = await api.post(`/register/${slug}`, {
        attendee_name: attendeeName.trim(),
        attendee_email: attendeeEmail.trim(),
        access_level: accessLevel,
        form_data: formData,
      })
      setSuccess(data.data)
    } catch (err) {
      const body = err?.response?.data || {}
      if (body.fields) {
        setErrors(body.fields)
      } else {
        setError(body.error || 'Registration failed')
      }
    } finally {
      setSubmitting(false)
    }
  }

  if (loading) return <div className="center-screen"><div className="spinner" /></div>
  if (error && !event) return <div className="center-screen"><div className="error">{error}</div></div>

  if (success) {
    return (
      <div className="center-screen">
        <div className="card auth-card" style={{ textAlign: 'center' }}>
          <div style={{ fontSize: 48, marginBottom: 8 }}>✓</div>
          <h2 style={{ fontFamily: 'var(--font-display)', fontSize: 22, marginBottom: 6 }}>
            You're registered!
          </h2>
          <p className="muted" style={{ marginBottom: 18 }}>
            Check your email for your e-ticket PDF with your entry QR code.
          </p>
          <div className="row" style={{ gap: 10, justifyContent: 'center', flexWrap: 'wrap' }}>
            {success.pdf_url && (
              <a className="btn primary" href={success.pdf_url} target="_blank" rel="noreferrer" style={{ display: 'inline-block' }}>
                Download e-ticket (PDF)
              </a>
            )}
            {success.ticket_url && (
              <a className="btn ghost" href={success.ticket_url} target="_blank" rel="noreferrer" style={{ display: 'inline-block' }}>
                View online
              </a>
            )}
          </div>
        </div>
      </div>
    )
  }

  const branding = event?.branding || {}
  const seatsLeft = event?.seats_left ?? null

  return (
    <div className="center-screen" style={{ padding: 20 }}>
      <div className="card" style={{ maxWidth: 560, width: '100%' }}>
        <div style={{ marginBottom: 20 }}>
          <h1 style={{ fontFamily: 'var(--font-display)', fontSize: 26, marginBottom: 4 }}>
            {event?.title}
          </h1>
          {event?.venue && <div className="muted">{event.venue}</div>}

          <div className="row" style={{ gap: 8, marginTop: 12, flexWrap: 'wrap' }}>
            {event?.price !== null && event?.price !== undefined && (
              <span className="pill" style={{ background: 'var(--red)', color: '#fff' }}>
                {Number(event.price) > 0 ? `${event.price} ${event.currency || 'ALL'}` : 'FREE'}
              </span>
            )}
            {Number(event?.minimum_age) > 0 && (
              <span className="pill">{event.minimum_age}+ only</span>
            )}
            {event?.starts_at_utc && (
              <span className="pill">{new Date(event.starts_at_utc.replace(' ', 'T') + 'Z').toLocaleString()}</span>
            )}
          </div>

          {event?.description && (
            <p style={{ marginTop: 14, color: 'var(--text)', lineHeight: 1.5 }}>{event.description}</p>
          )}
          {seatsLeft !== null && (
            <div className="muted" style={{ marginTop: 10, fontSize: 13 }}>
              {seatsLeft > 0 ? `${seatsLeft} seats remaining` : 'Event at capacity — waitlist available'}
            </div>
          )}
        </div>

        <form onSubmit={submit}>
          <div className="field">
            <label htmlFor="attendee_name">Your name <span style={{ color: 'var(--red)' }}>*</span></label>
            <input
              id="attendee_name"
              autoFocus
              value={attendeeName}
              onChange={(e) => setAttendeeName(e.target.value)}
              placeholder="Jane Doe"
            />
            {errors.attendee_name && <span className="error">{errors.attendee_name}</span>}
          </div>

          <div className="field">
            <label htmlFor="attendee_email">Email <span style={{ color: 'var(--red)' }}>*</span></label>
            <input
              id="attendee_email"
              type="email"
              value={attendeeEmail}
              onChange={(e) => setAttendeeEmail(e.target.value)}
              placeholder="jane@example.com"
            />
            {errors.attendee_email && <span className="error">{errors.attendee_email}</span>}
          </div>

          <div className="field">
            <label htmlFor="access_level">Ticket type</label>
            <select id="access_level" value={accessLevel} onChange={(e) => setAccessLevel(e.target.value)}>
              <option value="general">General admission</option>
              <option value="vip">VIP</option>
              <option value="speaker">Speaker</option>
              <option value="staff">Staff</option>
            </select>
          </div>

          {event?.form_config?.length > 0 && (
            <>
              <hr style={{ margin: '20px 0', border: 'none', borderTop: '1px solid var(--line)' }} />
              <FormRenderer
                schema={event.form_config}
                value={formData}
                onChange={setFormData}
                errors={errors}
              />
            </>
          )}

          {error && <div className="error" style={{ marginTop: 14 }}>{error}</div>}

          <Button type="submit" loading={submitting} style={{ width: '100%', marginTop: 18 }}>
            Register now
          </Button>
        </form>
      </div>
    </div>
  )
}
