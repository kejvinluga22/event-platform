import { NavLink } from 'react-router-dom'
import { useAuth } from '../../contexts/AuthContext'

export default function Sidebar() {
  const { user, isAdmin } = useAuth()

  return (
    <aside className="sidebar">
      <div className="brand">
        <span className="dot">◆</span>
        SmartEvent
      </div>

      <nav>
        <NavLink to="/" end>
          <span>▦</span> Events
        </NavLink>
        {isAdmin() && (
          <NavLink to="/events/new">
            <span>＋</span> Create Event
          </NavLink>
        )}
        <NavLink to="/scan">
          <span>▣</span> Scanner (PWA)
        </NavLink>
        {user?.role === 'super_admin' && (
          <>
            <NavLink to="/users">
              <span>👤</span> Users
            </NavLink>
            <NavLink to="/settings">
              <span>⚙</span> Settings
            </NavLink>
          </>
        )}
      </nav>

      <div className="spacer" />

      <div className="user">
        <div className="name">{user?.full_name || user?.name}</div>
        <div className="role">{user?.role?.replace('_', ' ')}</div>
      </div>
    </aside>
  )
}
