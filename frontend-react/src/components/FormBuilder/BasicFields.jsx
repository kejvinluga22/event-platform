/**
 * BasicFields — the built-in questions every registration form has
 * (name, email, phone) plus the optional ones switched on in the event
 * settings (age, ticket type). Shared by the public registration page and
 * the live preview in the event editor so both always look the same.
 */

// If the organizer added their own phone field in the form builder,
// the built-in one is hidden so guests aren't asked twice.
const PHONE_RE = /phone|telefon|\btel\b|mobile|celular/i
export const hasCustomPhoneField = (schema = []) =>
  schema.some((f) => f.type === 'tel' || PHONE_RE.test(f.name || '') || PHONE_RE.test(f.label || ''))

const truthy = (v, fallback) => (v === undefined || v === null ? fallback : !!Number(v))

/** Works with both the public event payload and the editor's event state. */
export function basicSettings(event = {}, schema = []) {
  const issueTickets = truthy(event.issue_tickets, true)
  const fromString = String(event.ticket_type_options || '')
    .split(',').map((s) => s.trim()).filter(Boolean)
  const ticketTypes = event.ticket_types?.length ? event.ticket_types
    : fromString.length ? fromString : ['General', 'VIP']
  return {
    showPhone: !hasCustomPhoneField(schema),
    askAge: truthy(event.ask_age, false),
    askTicketType: issueTickets && truthy(event.ask_ticket_type, false),
    ticketTypes,
  }
}

const Req = () => <span style={{ color: 'var(--red)' }}> *</span>
const Tag = () => (
  <span className="pill" style={{ fontSize: 10, marginLeft: 8, padding: '1px 8px', verticalAlign: 'middle' }}>Basic</span>
)

export default function BasicFields({ settings, values, onChange, errors = {}, autoFocus = false, showTags = false }) {
  const { showPhone, askAge, askTicketType, ticketTypes } = settings
  const tag = showTags ? <Tag /> : null
  return (
    <>
      <div className="field">
        <label htmlFor="attendee_name">Your full name<Req />{tag}</label>
        <input
          id="attendee_name"
          autoFocus={autoFocus}
          value={values.name}
          onChange={(e) => onChange('name', e.target.value)}
          placeholder="First name — Last name"
        />
        {errors.attendee_name && <span className="error">{errors.attendee_name}</span>}
      </div>

      <div className="field">
        <label htmlFor="attendee_email">Email<Req />{tag}</label>
        <input
          id="attendee_email"
          type="email"
          value={values.email}
          onChange={(e) => onChange('email', e.target.value)}
          placeholder="jane@example.com"
        />
        {errors.attendee_email && <span className="error">{errors.attendee_email}</span>}
      </div>

      {showPhone && (
        <div className="field">
          <label htmlFor="attendee_phone">Phone number{tag}</label>
          <input
            id="attendee_phone"
            type="tel"
            value={values.phone}
            onChange={(e) => onChange('phone', e.target.value)}
            placeholder="+355 69 123 4567"
            maxLength={40}
          />
          {errors.phone && <span className="error">{errors.phone}</span>}
        </div>
      )}

      {askAge && (
        <div className="field">
          <label htmlFor="attendee_age">Age<Req />{tag}</label>
          <input
            id="attendee_age"
            type="number"
            min={1}
            max={120}
            value={values.age}
            onChange={(e) => onChange('age', e.target.value)}
            placeholder="e.g. 25"
          />
          {errors.attendee_age && <span className="error">{errors.attendee_age}</span>}
        </div>
      )}

      {askTicketType && (
        <div className="field">
          <label htmlFor="access_level">Ticket type<Req />{tag}</label>
          <select id="access_level" value={values.ticket} onChange={(e) => onChange('ticket', e.target.value)}>
            <option value="">Select…</option>
            {ticketTypes.map((t) => <option key={t} value={t}>{t}</option>)}
          </select>
          {errors.access_level && <span className="error">{errors.access_level}</span>}
        </div>
      )}
    </>
  )
}
