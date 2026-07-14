import { Routes, Route, Navigate, useLocation } from 'react-router-dom'
import { useAuth } from './contexts/AuthContext'
import Sidebar from './components/Common/Sidebar'
import Login from './pages/Login'
import EventsDashboard from './pages/Admin/EventsDashboard'
import EventWizard from './pages/Admin/EventWizard'
import SettingsPage from './pages/Admin/SettingsPage'
import UsersPage from './pages/Admin/UsersPage'
import PwaScannerPortal from './pages/Mobile/PwaScannerPortal'
import ScannerEventPicker from './pages/Mobile/ScannerEventPicker'
import RegistrationPortal from './pages/Public/RegistrationPortal'

function Protected({ children, roles }) {
  const { user, loading } = useAuth()
  const location = useLocation()
  if (loading) return <div className="center-screen"><div className="spinner" /></div>
  if (!user) return <Navigate to="/login" state={{ from: location }} replace />
  if (roles && !roles.includes(user.role)) return <Navigate to="/scan" replace />
  return children
}

/**
 * ScannerGate — wraps the generic /scan route. Scanner-role users are
 * routed to an event picker instead of the camera, so they can't
 * accidentally check guests into the wrong event when multiple are live.
 * All other authenticated roles see the regular scanner.
 */
function ScannerGate() {
  const { user } = useAuth()
  if (user?.role === 'scanner') return <ScannerEventPicker />
  return <PwaScannerPortal />
}

function AdminLayout({ children, crumb }) {
  const { user, logout } = useAuth()
  return (
    <div className="app-shell">
      <Sidebar />
      <div className="main">
        <header className="topbar">
          <span className="crumb">{crumb}</span>
          <div className="row">
            <span className="muted" style={{ fontSize: 13 }}>{user?.email}</span>
            <button className="btn ghost sm" onClick={logout}>Sign out</button>
          </div>
        </header>
        <div className="content">{children}</div>
      </div>
    </div>
  )
}

export default function App() {
  return (
    <Routes>
      {/* Public, no auth */}
      <Route path="/login" element={<Login />} />
      <Route path="/e/:slug" element={<RegistrationPortal />} />

      {/* Mobile PWA scanner — any authenticated role */}
      <Route path="/scan" element={<Protected><ScannerGate /></Protected>} />
      <Route path="/events/:id/scan" element={<Protected><PwaScannerPortal /></Protected>} />

      {/* Admin / organizer control center */}
      <Route
        path="/"
        element={
          <Protected roles={['super_admin', 'event_manager', 'staff']}>
            <AdminLayout crumb="Dashboard / Events"><EventsDashboard /></AdminLayout>
          </Protected>
        }
      />
      <Route
        path="/events/new"
        element={
          <Protected roles={['super_admin', 'event_manager']}>
            <AdminLayout crumb="Dashboard / Events / New"><EventWizard /></AdminLayout>
          </Protected>
        }
      />
      <Route
        path="/events/:id/edit"
        element={
          <Protected roles={['super_admin', 'event_manager']}>
            <AdminLayout crumb="Dashboard / Events / Edit"><EventWizard /></AdminLayout>
          </Protected>
        }
      />
      <Route
        path="/settings"
        element={
          <Protected roles={['super_admin']}>
            <AdminLayout crumb="Dashboard / Settings"><SettingsPage /></AdminLayout>
          </Protected>
        }
      />
      <Route
        path="/users"
        element={
          <Protected roles={['super_admin']}>
            <AdminLayout crumb="Dashboard / Users"><UsersPage /></AdminLayout>
          </Protected>
        }
      />

      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}
