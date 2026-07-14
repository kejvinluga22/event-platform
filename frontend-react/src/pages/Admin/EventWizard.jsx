import { useEffect, useState, useCallback } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import api from '../../api/client'
import Button from '../../components/Common/Button'
import FormRenderer from '../../components/FormBuilder/FormRenderer'

const STEPS = ['Details', 'Schedule & Capacity', 'Registration Form', 'Review']
const FIELD_TYPES = ['text', 'email', 'tel', 'number', 'textarea', 'select', 'radio', 'checkbox', 'file']

const emptyEvent = {
  title: '', slug: '', description: '', venue: '', timezone: 'UTC',
  starts_at_utc: '', ends_at_utc: '', capacity: 100, waitlist_enabled: false,
  price: '', currency: 'ALL', minimum_age: 18, organizer_name: '',
  email_subject: '',
  status: 'draft',
}

/**
 * EventWizard — the multi-step onboarding layout for creating or editing an
 * event. Step 3 is the schema-driven dynamic form builder: organizers compose
 * the field map that the public portal later renders on the fly.
 */
export default function EventWizard() {
  const { id } = useParams()
  const editing = Boolean(id)
  const navigate = useNavigate()

  const [step, setStep] = useState(0)
  const [event, setEvent] = useState(emptyEvent)
  const [fields, setFields] = useState([])
  const [preview, setPreview] = useState({})
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(editing)

  const load = useCallback(async () => {
    if (!editing) return
    try {
      const { data } = await api.get(`/events/${id}`)
      const e = data.data.event
      setEvent({
        title: e.title || '', slug: e.slug || '', description: e.description || '',
        venue: e.venue || '', timezone: e.timezone || 'UTC',
        starts_at_utc: (e.starts_at_utc || '').replace(' ', 'T').slice(0, 16),
        ends_at_utc: (e.ends_at_utc || '').replace(' ', 'T').slice(0, 16),
        capacity: e.capacity ?? 100, waitlist_enabled: !!e.waitlist_enabled,
        price: e.price ?? '', currency: e.currency || 'ALL',
        minimum_age: e.minimum_age ?? 0, organizer_name: e.organizer_name || '',
        email_subject: e.email_subject || '',
        status: e.status || 'draft',
      })
      setFields(Array.isArray(e.form_config) ? e.form_config : [])
    } catch (err) {
      setError(err?.response?.data?.error || 'Failed to load event')
    } finally {
      setLoading(false)
    }
  }, [editing, id])

  useEffect(() => { load() }, [load])

  const setField = (k, v) => setEvent((prev) => ({ ...prev, [k]: v }))

  // ---- dynamic form builder helpers ----
  const addField = () => setFields((f) => [
    ...f,
    { name: `field_${f.length + 1}`, label: 'New field', type: 'text', required: false },
  ])
  const updateField = (i, patch) => setFields((f) => f.map((x, j) => (j === i ? { ...x, ...patch } : x)))
  const removeField = (i) => setFields((f) => f.filter((_, j) => j !== i))
  const moveField = (i, dir) => setFields((f) => {
    const j = i + dir
    if (j < 0 || j >= f.length) return f
    const copy = [...f]; [copy[i], copy[j]] = [copy[j], copy[i]]; return copy
  })

  const buildPayload = () => ({
    ...event,
    capacity: Number(event.capacity) || 0,
    price: event.price === '' || event.price === null ? null : Number(event.price),
    minimum_age: Number(event.minimum_age) || 0,
    starts_at_utc: event.starts_at_utc ? event.starts_at_utc.replace('T', ' ') + ':00' : null,
    ends_at_utc: event.ends_at_utc ? event.ends_at_utc.replace('T', ' ') + ':00' : null,
    form_config: fields,
  })

  const save = async () => {
    setError('')
    if (!event.title.trim()) { setStep(0); setError('Title is required'); return }
    setSaving(true)
    try {
      const payload = buildPayload()
      if (editing) {
        await api.put(`/events/${id}`, payload)
        await api.put(`/events/${id}/form`, { form_config: fields })
      } else {
        await api.post('/events', payload)
      }
      navigate('/')
    } catch (err) {
      setError(err?.response?.data?.error || 'Save failed')
    } finally {
      setSaving(false)
    }
  }

  if (loading) return <div className="spinner" />

  return (
    <div style={{ maxWidth: 760 }}>
      <button
        className="btn ghost sm"
        onClick={() => navigate('/')}
        style={{ marginBottom: 14 }}
      >← Back to events</button>
      <h1 style={{ fontFamily: 'var(--font-display)', fontSize: 26, marginBottom: 6 }}>
        {editing ? 'Edit event' : 'Create event'}
      </h1>
      <div className="muted" style={{ marginBottom: 18 }}>Step {step + 1} of {STEPS.length} — {STEPS[step]}</div>

      <div className="steps">
        {STEPS.map((_, i) => <div key={i} className={`step ${i <= step ? 'done' : ''}`} />)}
      </div>

      <div className="card">
        {error && <div className="error" style={{ marginBottom: 14 }}>{error}</div>}

        {/* STEP 0 — Details */}
        {step === 0 && (
          <>
            <div className="field">
              <label>Event title <span style={{ color: 'var(--red)' }}>*</span></label>
              <input value={event.title} onChange={(e) => setField('title', e.target.value)} placeholder="Tech Summit 2026" />
              {editing && event.slug && (
                <div className="muted" style={{ fontSize: 12, marginTop: 4 }}>
                  Public URL: <code>/e/{event.slug}</code>
                </div>
              )}
            </div>
            <div className="field">
              <label>Description</label>
              <textarea rows={4} value={event.description} onChange={(e) => setField('description', e.target.value)} />
            </div>
            <div className="field">
              <label>Venue</label>
              <input value={event.venue} onChange={(e) => setField('venue', e.target.value)} placeholder="Main Conference Hall" />
            </div>
          </>
        )}

        {/* STEP 1 — Schedule & Capacity */}
        {step === 1 && (
          <>
            <div className="grid cols-2">
              <div className="field">
                <label>Starts (UTC)</label>
                <input type="datetime-local" value={event.starts_at_utc} onChange={(e) => setField('starts_at_utc', e.target.value)} />
              </div>
              <div className="field">
                <label>Ends (UTC)</label>
                <input type="datetime-local" value={event.ends_at_utc} onChange={(e) => setField('ends_at_utc', e.target.value)} />
              </div>
            </div>
            <div className="grid cols-2">
              <div className="field">
                <label>Timezone</label>
                <input value={event.timezone} onChange={(e) => setField('timezone', e.target.value)} placeholder="UTC" />
              </div>
              <div className="field">
                <label>Capacity</label>
                <input type="number" min={0} value={event.capacity} onChange={(e) => setField('capacity', e.target.value)} />
              </div>
            </div>
            <label className="row" style={{ gap: 8, cursor: 'pointer' }}>
              <input type="checkbox" checked={event.waitlist_enabled} onChange={(e) => setField('waitlist_enabled', e.target.checked)} />
              <span>Enable waitlist when capacity is reached</span>
            </label>

            <hr style={{ margin: '24px 0', border: 'none', borderTop: '1px solid var(--line)' }} />
            <h3 style={{ marginBottom: 12, fontSize: 16 }}>Ticket details</h3>

            <div className="grid cols-2">
              <div className="field">
                <label>Price (leave blank for FREE)</label>
                <input
                  type="number" min={0} step="0.01"
                  value={event.price}
                  onChange={(e) => setField('price', e.target.value)}
                  placeholder="500"
                />
              </div>
              <div className="field">
                <label>Currency</label>
                <select value={event.currency} onChange={(e) => setField('currency', e.target.value)}>
                  <option value="ALL">ALL (Albanian Lek)</option>
                  <option value="EUR">EUR</option>
                  <option value="USD">USD</option>
                  <option value="GBP">GBP</option>
                </select>
              </div>
            </div>

            <div className="grid cols-2">
              <div className="field">
                <label>Minimum age (0 = no limit)</label>
                <input
                  type="number" min={0} max={99}
                  value={event.minimum_age}
                  onChange={(e) => setField('minimum_age', e.target.value)}
                  placeholder="18"
                />
              </div>
              <div className="field">
                <label>Organizer name (printed on ticket)</label>
                <input
                  value={event.organizer_name}
                  onChange={(e) => setField('organizer_name', e.target.value)}
                  placeholder="(optional)"
                />
              </div>
            </div>

            <div className="field">
              <label>Confirmation email subject (optional)</label>
              <input
                value={event.email_subject || ''}
                onChange={(e) => setField('email_subject', e.target.value)}
                placeholder={`e.g. "Your ${event.title || 'event'} ticket"`}
              />
              <div className="muted" style={{ fontSize: 12, marginTop: 4 }}>
                Used as the email subject when sending confirmation tickets to guests.
                Leave blank to use the default: "Your ticket for {event.title || '<event name>'}".
              </div>
            </div>
          </>
        )}

        {/* STEP 2 — Dynamic Form Builder */}
        {step === 2 && (
          <>
            <div className="row between" style={{ marginBottom: 12 }}>
              <h3>Registration form fields</h3>
              <button className="btn ghost sm" onClick={addField}>+ Add field</button>
            </div>
            {fields.length === 0 && <div className="muted">No custom fields. Attendees still provide name and email.</div>}
            {fields.map((f, i) => (
              <div key={i} className="card" style={{ background: 'var(--bg)', marginBottom: 12 }}>
                <div className="grid cols-2">
                  <div className="field">
                    <label>Field key (name)</label>
                    <input value={f.name} onChange={(e) => updateField(i, { name: e.target.value })} />
                  </div>
                  <div className="field">
                    <label>Label</label>
                    <input value={f.label} onChange={(e) => updateField(i, { label: e.target.value })} />
                  </div>
                </div>
                <div className="grid cols-2">
                  <div className="field">
                    <label>Type</label>
                    <select value={f.type} onChange={(e) => updateField(i, { type: e.target.value })}>
                      {FIELD_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
                    </select>
                  </div>
                  <div className="field">
                    <label>Placeholder</label>
                    <input value={f.placeholder || ''} onChange={(e) => updateField(i, { placeholder: e.target.value })} />
                  </div>
                </div>
                {['select', 'radio', 'checkbox'].includes(f.type) && (
                  <div className="field">
                    <label>Options (comma-separated)</label>
                    <input
                      value={(f.options || []).join(', ')}
                      onChange={(e) => updateField(i, { options: e.target.value.split(',').map((s) => s.trim()).filter(Boolean) })}
                      placeholder="Corporate, Individual, Student"
                    />
                  </div>
                )}
                <div className="grid cols-2">
                  <div className="field">
                    <label>Conditional — show if field</label>
                    <input
                      value={f.visible_if?.field || ''}
                      onChange={(e) => updateField(i, { visible_if: e.target.value ? { field: e.target.value, equals: f.visible_if?.equals || '' } : undefined })}
                      placeholder="(optional) reg_type"
                    />
                  </div>
                  <div className="field">
                    <label>…equals value</label>
                    <input
                      value={f.visible_if?.equals || ''}
                      disabled={!f.visible_if?.field}
                      onChange={(e) => updateField(i, { visible_if: { field: f.visible_if?.field, equals: e.target.value } })}
                      placeholder="Corporate"
                    />
                  </div>
                </div>
                <div className="row between">
                  <label className="row" style={{ gap: 6, cursor: 'pointer' }}>
                    <input type="checkbox" checked={!!f.required} onChange={(e) => updateField(i, { required: e.target.checked })} />
                    <span>Required</span>
                  </label>
                  <div className="row" style={{ gap: 6 }}>
                    <button className="btn ghost sm" onClick={() => moveField(i, -1)}>↑</button>
                    <button className="btn ghost sm" onClick={() => moveField(i, 1)}>↓</button>
                    <button className="btn ghost sm" onClick={() => removeField(i)}>Remove</button>
                  </div>
                </div>
              </div>
            ))}
            {fields.length > 0 && (
              <div className="card" style={{ marginTop: 14 }}>
                <h3 style={{ marginBottom: 12 }}>Live preview</h3>
                <FormRenderer schema={fields} value={preview} onChange={setPreview} />
              </div>
            )}
          </>
        )}

        {/* STEP 3 — Review */}
        {step === 3 && (
          <>
            <h3 style={{ marginBottom: 12 }}>Review &amp; save</h3>
            <table className="data">
              <tbody>
                <tr><th>Title</th><td>{event.title || '—'}</td></tr>
                <tr><th>Venue</th><td>{event.venue || '—'}</td></tr>
                <tr><th>Schedule</th><td>{event.starts_at_utc || '—'} → {event.ends_at_utc || '—'} ({event.timezone})</td></tr>
                <tr><th>Capacity</th><td>{event.capacity} {event.waitlist_enabled ? '(waitlist on)' : ''}</td></tr>
                <tr><th>Price</th><td>{event.price === '' || Number(event.price) === 0 ? 'FREE' : `${event.price} ${event.currency}`}</td></tr>
                <tr><th>Minimum age</th><td>{Number(event.minimum_age) > 0 ? `${event.minimum_age}+` : 'No limit'}</td></tr>
                <tr><th>Organizer</th><td>{event.organizer_name || '—'}</td></tr>
                <tr><th>Custom fields</th><td>{fields.length}</td></tr>
                <tr><th>Status</th><td><span className={`pill ${event.status}`}>{event.status}</span></td></tr>
              </tbody>
            </table>
          </>
        )}

        <div className="row between" style={{ marginTop: 22 }}>
          <button className="btn ghost" disabled={step === 0} onClick={() => setStep((s) => s - 1)}>Back</button>
          {step < STEPS.length - 1
            ? <Button onClick={() => setStep((s) => s + 1)}>Next</Button>
            : <Button loading={saving} onClick={save}>{editing ? 'Save changes' : 'Create event'}</Button>}
        </div>
      </div>
    </div>
  )
}
