import React from 'react'
import ReactDOM from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import { AuthProvider } from './contexts/AuthContext'
import ErrorBoundary from './components/Common/ErrorBoundary'
import App from './App'
import './index.css'

// Note: React.StrictMode is intentionally not used here. It double-mounts every
// component in dev to surface side-effect bugs, which is great in principle —
// but it conflicts badly with the html5-qrcode library, which throws "Cannot
// stop, scanner is not running or paused" when the duplicate mount tears down
// a camera that hadn't finished starting yet. Production builds never trigger
// this regardless.
ReactDOM.createRoot(document.getElementById('root')).render(
  <ErrorBoundary>
    <BrowserRouter>
      <AuthProvider>
        <App />
      </AuthProvider>
    </BrowserRouter>
  </ErrorBoundary>
)
