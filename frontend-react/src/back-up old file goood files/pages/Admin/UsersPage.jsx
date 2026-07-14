import { useEffect, useState, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import api from '../../api/client'
import Button from '../../components/Common/Button'
import { useAuth } from '../../contexts/AuthContext'

const ROLES = [
  { value: 'super_admin',   label: 'Super Admin',   desc: 'Full system access' },
  { value: 'event_manager', label: 'Event Manager', desc: 'Manage own events + reports' },
  { value: 'staff',         label: 'Staff',         desc: 'Check-ins + walk-ins' },
  { value: 'scanner',       label: 'Scanner',       desc: 'Camera scanning only' },
]

const blank = { email: '', password: '', full_name: '', role: 'staff' }

/**
 * UsersPage — super_admin-only user management. Create accounts, edit roles,
 * activate / deactivate, reset passwords, delete. Cannot operate on self.
 */
export default function UsersPage() {
  const { user: me } = useAuth()
  const navigate = useNavigate()
  const [users, setUsers] = useState([])
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')

  const [showNew, setShowNew] = useState(false)
  const [newUser, setNewUser] = useState(blank)
  const [creating, setCreating] = useState(false)

  const [editing, setEditing] = useState(null)   // { id, full_name, role, is_active }
  const [resetting, setResetting] = useState(null) // userId
  const [newPass, setNewPass] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const { data } = await api.get('/users')
      setUsers(data.data.users || [])
    } catch (e) {
      setErr(e?.response?.data?.error || 'Failed to load users')
    } finally { setLoading(false) }
  }, [])

  useEffect(() => { load() }, [load])

  const create = async (e) => {
    e.preventDefault()
    setErr('')
    setCreating(true)
    try {
      await api.post('/users', newUser)
      setNewUser(blank)
      setShowNew(false)
      await load()
    } catch (e) {
      setErr(e?.response?.data?.error || 'Create failed')
    } finally { setCreating(false) }
  }

  const saveEdit = async () => {
    try {
      await api.put(`/users/${editing.id}`, {
        full_name: editing.full_name,
        role:      editing.role,
        is_active: editing.is_active,
      })
      setEditing(null)
      await load()
    } catch (e) {
      alert(e?.response?.data?.error || 'Update failed')
    }
  }

  const toggleActive = async (u) => {
    try {
      await api.put(`/users/${u.id}`, { is_active: !u.is_active })
      await load()
    } catch (e) {
      alert(e?.response?.data?.error || 'Update failed')
    }
  }

  const submitReset = async () => {
    if (newPass.length < 8) { alert('Password must be at least 8 characters'); return }
    try {
      await api.post(`/users/${resetting}/reset-password`, { password: newPass })
      setResetting(null); setNewPass('')
      alert('Password reset')
    } catch (e) {
      alert(e?.response?.data?.error || 'Reset failed')
    }
  }

  const remove = async (u) => {
    if (!confirm(`Delete user ${u.email}?\n\nThis cannot be undone.`)) return
    try {
      await api.delete(`/users/${u.id}`)
      await load()
    } catch (e) {
      alert(e?.response?.data?.error || 'Delete failed')
    }
  }

  if (loading) return <div className="spinner" />

  return (
    <div style={{ maxWidth: 1000 }}>
      <button
        className="btn ghost sm"
        onClick={() => navigate('/')}
        style={{ marginBottom: 14 }}
      >← Back to events</button>
      <div className="row between" style={{ marginBottom: 18 }}>
        <div>
          <h1 style={{ fontFamily: 'var(--font-display)', fontSize: 26 }}>Users</h1>
          <div className="muted" style={{ fontSize: 14 }}>{users.length} accounts</div>
        </div>
        <Button onClick={() => setShowNew(true)}>+ Create user</Button>
      </div>

      {err && <div className="error" style={{ marginBottom: 14 }}>{err}</div>}

      <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
        <table className="data">
          <thead>
            <tr>
              <th>User</th>
              <th>Role</th>
              <th>Status</th>
              <th>Last login</th>
              <th style={{ textAlign: 'right' }}>Actions</th>
            </tr>
          </thead>
          <tbody>
            {users.map((u) => {
              const isMe = u.id === me?.id
              return (
                <tr key={u.id}>
                  <td>
                    <div style={{ fontWeight: 600 }}>{u.full_name || u.email.split('@')[0]}</div>
                    <div className="muted" style={{ fontSize: 12 }}>{u.email}{isMe ? ' · (you)' : ''}</div>
                  </td>
                  <td><span className="pill">{u.role}</span></td>
                  <td>
                    <span className={`pill ${u.is_active ? 'confirmed' : 'cancelled'}`}>
                      {u.is_active ? 'active' : 'disabled'}
                    </span>
                  </td>
                  <td className="muted">{u.last_login_at || '—'}</td>
                  <td>
                    <div className="row" style={{ justifyContent: 'flex-end', gap: 6, flexWrap: 'wrap' }}>
                      {!isMe && (
                        <>
                          <button className="btn ghost sm" onClick={() => setEditing(u)}>Edit</button>
                          <button className="btn ghost sm" onClick={() => toggleActive(u)}>
                            {u.is_active ? 'Disable' : 'Activate'}
                          </button>
                          <button className="btn ghost sm" onClick={() => { setResetting(u.id); setNewPass('') }}>
                            🔑 Reset password
                          </button>
                          <button
                            className="btn ghost sm"
                            style={{ color: '#b91c1c', borderColor: '#fecaca' }}
                            onClick={() => remove(u)}
                          >🗑</button>
                        </>
                      )}
                    </div>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      {/* Create user modal */}
      {showNew && (
        <Modal title="Create new user" onClose={() => setShowNew(false)}>
          <form onSubmit={create}>
            <div className="field">
              <label>Full name</label>
              <input value={newUser.full_name} onChange={(e) => setNewUser({ ...newUser, full_name: e.target.value })} />
            </div>
            <div className="field">
              <label>Email</label>
              <input type="email" required value={newUser.email} onChange={(e) => setNewUser({ ...newUser, email: e.target.value })} />
            </div>
            <div className="field">
              <label>Password (min 8 chars)</label>
              <input type="password" required minLength={8} value={newUser.password} onChange={(e) => setNewUser({ ...newUser, password: e.target.value })} />
            </div>
            <div className="field">
              <label>Role</label>
              <select value={newUser.role} onChange={(e) => setNewUser({ ...newUser, role: e.target.value })}>
                {ROLES.map((r) => <option key={r.value} value={r.value}>{r.label} — {r.desc}</option>)}
              </select>
            </div>
            <div className="row between" style={{ marginTop: 16 }}>
              <button type="button" className="btn ghost" onClick={() => setShowNew(false)}>Cancel</button>
              <Button type="submit" loading={creating}>Create</Button>
            </div>
          </form>
        </Modal>
      )}

      {/* Edit modal */}
      {editing && (
        <Modal title="Edit user" onClose={() => setEditing(null)}>
          <div className="muted" style={{ fontSize: 13, marginBottom: 12 }}>{editing.email}</div>
          <div className="field">
            <label>Full name</label>
            <input value={editing.full_name || ''} onChange={(e) => setEditing({ ...editing, full_name: e.target.value })} />
          </div>
          <div className="field">
            <label>Role</label>
            <select value={editing.role} onChange={(e) => setEditing({ ...editing, role: e.target.value })}>
              {ROLES.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
            </select>
          </div>
          <label className="row" style={{ gap: 8, cursor: 'pointer' }}>
            <input type="checkbox" checked={!!editing.is_active} onChange={(e) => setEditing({ ...editing, is_active: e.target.checked })} />
            <span>Active</span>
          </label>
          <div className="row between" style={{ marginTop: 16 }}>
            <button type="button" className="btn ghost" onClick={() => setEditing(null)}>Cancel</button>
            <Button onClick={saveEdit}>Save</Button>
          </div>
        </Modal>
      )}

      {/* Reset password modal */}
      {resetting && (
        <Modal title="Reset password" onClose={() => setResetting(null)}>
          <div className="field">
            <label>New password (min 8 chars)</label>
            <input
              type="password"
              autoFocus
              minLength={8}
              value={newPass}
              onChange={(e) => setNewPass(e.target.value)}
              placeholder="Enter a new password"
            />
          </div>
          <div className="muted" style={{ fontSize: 13 }}>
            Tell the user the new password through a secure channel. They can change it themselves later.
          </div>
          <div className="row between" style={{ marginTop: 16 }}>
            <button type="button" className="btn ghost" onClick={() => setResetting(null)}>Cancel</button>
            <Button onClick={submitReset}>Reset</Button>
          </div>
        </Modal>
      )}
    </div>
  )
}

function Modal({ title, onClose, children }) {
  return (
    <div
      onClick={onClose}
      style={{
        position: 'fixed', inset: 0, background: 'rgba(0,0,0,.4)',
        display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 100,
      }}
    >
      <div
        onClick={(e) => e.stopPropagation()}
        className="card"
        style={{ width: 'min(440px, 92vw)', background: '#fff' }}
      >
        <h2 style={{ fontSize: 18, marginBottom: 14 }}>{title}</h2>
        {children}
      </div>
    </div>
  )
}
