import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import api from '../../api/client'
import Button from '../../components/Common/Button'

/**
 * SettingsPage — SMTP credentials editor (super_admin only). Sending the
 * confirmation emails depends on these. Provider presets pre-fill the host
 * and port so the user only needs to enter their email + app password.
 */
export default function SettingsPage() {
  const navigate = useNavigate()
  const [smtp, setSmtp] = useState({
    provider: 'gmail', host: '', port: 587, encryption: 'tls',
    user: '', pass: '', from_email: '', from_name: '', pass_set: false,
  })
  const [providers, setProviders] = useState({})
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [msg, setMsg] = useState({ type: '', text: '' })
  const [testTo, setTestTo] = useState('')
  const [testing, setTesting] = useState(false)

  useEffect(() => {
    const load = async () => {
      try {
        const { data } = await api.get('/settings')
        setSmtp({ ...data.data.smtp, pass: '' })
        setProviders(data.data.providers || {})
        if (data.data.smtp.from_email) setTestTo(data.data.smtp.from_email)
      } catch (err) {
        setMsg({ type: 'error', text: err?.response?.data?.error || 'Failed to load settings' })
      } finally { setLoading(false) }
    }
    load()
  }, [])

  const setField = (k, v) => setSmtp((s) => ({ ...s, [k]: v }))

  const changeProvider = (name) => {
    const preset = providers[name]
    setSmtp((s) => ({
      ...s,
      provider: name,
      host: preset?.host ?? s.host,
      port: preset?.port ?? s.port,
      encryption: preset?.encryption ?? s.encryption,
    }))
  }

  const save = async (e) => {
    e.preventDefault()
    setMsg({ type: '', text: '' })
    setSaving(true)
    try {
      await api.put('/settings/smtp', smtp)
      setMsg({ type: 'success', text: 'SMTP settings saved.' })
      setSmtp((s) => ({ ...s, pass: '', pass_set: s.pass_set || !!s.pass }))
    } catch (err) {
      setMsg({ type: 'error', text: err?.response?.data?.error || 'Save failed' })
    } finally { setSaving(false) }
  }

  const sendTest = async () => {
    setMsg({ type: '', text: '' })
    setTesting(true)
    try {
      const { data } = await api.post('/settings/smtp/test', { to: testTo })
      setMsg({ type: 'success', text: `Test email sent via ${data.data.provider}.` })
    } catch (err) {
      setMsg({ type: 'error', text: err?.response?.data?.error || 'Test failed — check your credentials' })
    } finally { setTesting(false) }
  }

  if (loading) return <div className="spinner" />

  return (
    <div style={{ maxWidth: 720 }}>
      <button
        className="btn ghost sm"
        onClick={() => navigate('/')}
        style={{ marginBottom: 14 }}
      >← Back to events</button>
      <h1 style={{ fontFamily: 'var(--font-display)', fontSize: 26, marginBottom: 6 }}>Settings</h1>
      <div className="muted" style={{ marginBottom: 22 }}>
        Configure SMTP credentials so registration confirmation emails get delivered.
      </div>

      <div className="card">
        <h2 style={{ fontSize: 18, marginBottom: 4 }}>Email (SMTP)</h2>
        <div className="muted" style={{ fontSize: 13, marginBottom: 16 }}>
          For Gmail accounts, use a 16-character <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noreferrer">App Password</a> — not your account password.
        </div>

        <form onSubmit={save}>
          <div className="field">
            <label>Provider</label>
            <select value={smtp.provider} onChange={(e) => changeProvider(e.target.value)}>
              {Object.entries(providers).map(([k, p]) => (
                <option key={k} value={k}>{p.label || k}</option>
              ))}
            </select>
          </div>

          <div className="grid cols-2">
            <div className="field">
              <label>SMTP host</label>
              <input value={smtp.host} onChange={(e) => setField('host', e.target.value)} />
            </div>
            <div className="field">
              <label>Port</label>
              <input type="number" value={smtp.port} onChange={(e) => setField('port', Number(e.target.value))} />
            </div>
          </div>

          <div className="field">
            <label>Encryption</label>
            <select value={smtp.encryption} onChange={(e) => setField('encryption', e.target.value)}>
              <option value="tls">STARTTLS (port 587)</option>
              <option value="ssl">SSL/TLS (port 465)</option>
            </select>
          </div>

          <hr style={{ margin: '20px 0', border: 'none', borderTop: '1px solid var(--line)' }} />

          <div className="field">
            <label>SMTP username (email)</label>
            <input
              type="email"
              autoComplete="off"
              value={smtp.user}
              onChange={(e) => setField('user', e.target.value)}
              placeholder="you@gmail.com"
            />
          </div>

          <div className="field">
            <label>
              SMTP password {smtp.pass_set && !smtp.pass && (
                <span className="muted" style={{ fontSize: 12 }}>— (already saved; leave blank to keep)</span>
              )}
            </label>
            <input
              type="password"
              autoComplete="new-password"
              value={smtp.pass}
              onChange={(e) => setField('pass', e.target.value)}
              placeholder={smtp.pass_set ? '••••••••••••••••' : 'Enter your password / app password'}
            />
          </div>

          <hr style={{ margin: '20px 0', border: 'none', borderTop: '1px solid var(--line)' }} />

          <div className="grid cols-2">
            <div className="field">
              <label>"From" email</label>
              <input
                type="email"
                value={smtp.from_email}
                onChange={(e) => setField('from_email', e.target.value)}
                placeholder="no-reply@yourevent.com"
              />
            </div>
            <div className="field">
              <label>"From" name</label>
              <input value={smtp.from_name} onChange={(e) => setField('from_name', e.target.value)} placeholder="Smart Event" />
            </div>
          </div>

          {msg.text && (
            <div className={msg.type === 'error' ? 'error' : 'success'} style={{ marginTop: 12 }}>
              {msg.text}
            </div>
          )}

          <div className="row between" style={{ marginTop: 22 }}>
            <Button type="submit" loading={saving}>Save SMTP settings</Button>
          </div>
        </form>

        <hr style={{ margin: '24px 0', border: 'none', borderTop: '1px solid var(--line)' }} />

        <h3 style={{ fontSize: 15, marginBottom: 6 }}>Send a test email</h3>
        <div className="muted" style={{ fontSize: 13, marginBottom: 10 }}>
          Verifies your credentials work before any guest registers.
        </div>
        <div className="row" style={{ gap: 8 }}>
          <input
            type="email"
            value={testTo}
            onChange={(e) => setTestTo(e.target.value)}
            placeholder="you@example.com"
            style={{ flex: 1 }}
          />
          <Button onClick={sendTest} loading={testing}>Send test</Button>
        </div>
      </div>
    </div>
  )
}
