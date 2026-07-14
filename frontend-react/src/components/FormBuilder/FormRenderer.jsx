import { useState } from 'react'

/**
 * FormRenderer
 * Reads a JSON field schema and renders inputs on the fly — no static layout.
 * Supports: text, email, number, textarea, select (dropdown), checkbox group,
 * radio group, file upload. Honours conditional visibility (visible_if) and
 * per-field validation (required, maxLength, regex).
 *
 * Schema field shape:
 * {
 *   name: "company", label: "Company Name", type: "text",
 *   required: true, maxLength: 80, regex: "^[A-Za-z ]+$",
 *   options: ["A","B"],                       // for select/radio/checkbox
 *   visible_if: { field: "reg_type", equals: "Corporate" }
 * }
 */
export default function FormRenderer({ schema = [], value = {}, onChange, errors = {} }) {
  const [touched, setTouched] = useState({})

  const set = (name, v) => {
    onChange?.({ ...value, [name]: v })
    setTouched((t) => ({ ...t, [name]: true }))
  }

  const isVisible = (field) => {
    if (!field.visible_if) return true
    const { field: dep, equals } = field.visible_if
    return value[dep] === equals
  }

  return (
    <div>
      {schema.filter(isVisible).map((field) => {
        const err = errors[field.name]
        const common = { id: field.name, value: value[field.name] ?? '' }

        return (
          <div className="field" key={field.name}>
            <label htmlFor={field.name}>
              {field.label}
              {field.required && <span style={{ color: 'var(--red)' }}> *</span>}
            </label>

            {(['text', 'email', 'number', 'tel'].includes(field.type)) && (
              <input
                type={field.type}
                placeholder={field.placeholder || ''}
                maxLength={field.maxLength || undefined}
                {...common}
                onChange={(e) => set(field.name, e.target.value)}
              />
            )}

            {field.type === 'textarea' && (
              <textarea
                rows={4}
                placeholder={field.placeholder || ''}
                {...common}
                onChange={(e) => set(field.name, e.target.value)}
              />
            )}

            {field.type === 'select' && (
              <select {...common} onChange={(e) => set(field.name, e.target.value)}>
                <option value="">Select…</option>
                {(field.options || []).map((o) => (
                  <option key={o} value={o}>{o}</option>
                ))}
              </select>
            )}

            {field.type === 'radio' && (
              <div className="row" style={{ flexWrap: 'wrap', gap: 14 }}>
                {(field.options || []).map((o) => (
                  <label key={o} className="row" style={{ gap: 6, fontWeight: 400 }}>
                    <input
                      type="radio"
                      name={field.name}
                      checked={value[field.name] === o}
                      onChange={() => set(field.name, o)}
                    />
                    {o}
                  </label>
                ))}
              </div>
            )}

            {field.type === 'checkbox' && (
              <div className="row" style={{ flexWrap: 'wrap', gap: 14 }}>
                {(field.options || []).map((o) => {
                  const arr = Array.isArray(value[field.name]) ? value[field.name] : []
                  return (
                    <label key={o} className="row" style={{ gap: 6, fontWeight: 400 }}>
                      <input
                        type="checkbox"
                        checked={arr.includes(o)}
                        onChange={(e) =>
                          set(
                            field.name,
                            e.target.checked ? [...arr, o] : arr.filter((x) => x !== o)
                          )
                        }
                      />
                      {o}
                    </label>
                  )
                })}
              </div>
            )}

            {field.type === 'file' && (
              <input
                type="file"
                accept={field.accept || '.pdf,.jpg,.jpeg,.png'}
                onChange={(e) => set(field.name, e.target.files?.[0]?.name || '')}
              />
            )}

            {field.help && <span className="muted" style={{ fontSize: 12 }}>{field.help}</span>}
            {err && touched[field.name] !== false && <span className="error">{err}</span>}
          </div>
        )
      })}
    </div>
  )
}

/** Client-side validator mirroring the server schema rules. */
export function validateForm(schema, value) {
  const errors = {}
  for (const f of schema) {
    if (f.visible_if && value[f.visible_if.field] !== f.visible_if.equals) continue
    const v = value[f.name]
    if (f.required && (v === undefined || v === '' || (Array.isArray(v) && !v.length))) {
      errors[f.name] = 'This field is required'
      continue
    }
    if (v && f.maxLength && String(v).length > f.maxLength) errors[f.name] = `Max ${f.maxLength} characters`
    if (v && f.regex && !new RegExp(f.regex).test(String(v))) errors[f.name] = 'Invalid format'
    if (v && f.type === 'email' && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(String(v))) errors[f.name] = 'Invalid email'
  }
  return errors
}
