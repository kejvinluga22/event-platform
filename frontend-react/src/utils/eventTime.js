/**
 * Event times are stored exactly as the organizer typed them (the event's
 * local time). Show them as-is — never convert through the viewer's browser
 * timezone, which shifted the time by an hour (or more) on the public page.
 */
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

function parts(value) {
  const m = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/)
  if (!m) return null
  const [, y, mo, d, h, mi] = m
  // Weekday from the calendar date only (UTC math, so no timezone shift).
  const wd = new Date(Date.UTC(+y, +mo - 1, +d)).getUTCDay()
  return { y: +y, mo: +mo, d: +d, time: `${h}:${mi}`, wd }
}

const dayLabel = (p) => `${DAYS[p.wd]}, ${p.d} ${MONTHS[p.mo - 1]} ${p.y}`

/** "Fri, 20 Nov 2026 · 20:00 – 23:00"  or  "Fri, 20 Nov 2026 · 20:00 – Sat, 21 Nov 2026 · 02:00" */
export function formatEventTime(start, end) {
  const s = parts(start)
  if (!s) return ''
  const e = parts(end)
  if (!e) return `${dayLabel(s)} · ${s.time}`
  const sameDay = s.y === e.y && s.mo === e.mo && s.d === e.d
  return sameDay
    ? `${dayLabel(s)} · ${s.time} – ${e.time}`
    : `${dayLabel(s)} · ${s.time} – ${dayLabel(e)} · ${e.time}`
}
