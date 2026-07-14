import { useState } from 'react'
import { useNavigate, useLocation, Navigate } from 'react-router-dom'
import { useAuth } from '../contexts/AuthContext'
import Button from '../components/Common/Button'

/**
 * Login — credential gateway for the admin control center and the PWA scanner.
 * On success the API sets an HttpOnly cookie; we also keep the bearer token in
 * localStorage as a header fallback (see api/client.js interceptor).
 */
export default function Login() {
  const { user, login, loading } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  // Already signed in — bounce to wherever they were headed.
  if (!loading && user) {
    const dest = location.state?.from?.pathname || '/'
    return <Navigate to={dest} replace />
  }

  const submit = async (e) => {
    e.preventDefault()
    setError('')
    setBusy(true)
    try {
      const u = await login(email.trim(), password)
      // Scanner-only accounts have no dashboard access.
      const dest =
        location.state?.from?.pathname ||
        (u.role === 'scanner' ? '/scan' : '/')
      navigate(dest, { replace: true })
    } catch (err) {
      setError(err?.response?.data?.error || 'Unable to sign in. Check your credentials.')
    } finally {
      setBusy(false)
    }
  }
d
  return (
    <div className="center-screen">
      <div className="card auth-card">
        <div className="logo">Smart<span style={{ color: 'var(--red)' }}>Event</span></div>
        <div className="sub">Sign in to the organizer control center</div>

        <form onSubmit={submit}>
          <div className="field">
            <label htmlFor="email">Email</label>
            <input
              id="email"
              type="email"
              autoComplete="username"
              autoFocus
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="admin@smartevent.local"
              required
            />
          </div>

          <div className="field">
            <label htmlFor="password">Password</label>
            <input
              id="password"
              type="password"
              autoComplete="current-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="••••••••"
              required
            />
          </div>

          {error && <div className="error" style={{ marginBottom: 14 }}>{error}</div>}

          <Button type="submit" loading={busy} style={{ width: '100%' }}>
            Sign in
          </Button>
        </form>

        <div className="muted" style={{ fontSize: 12, marginTop: 18, textAlign: 'center' }}>
         
        </div>
      </div>
    </div>
  )
}
