import { Component } from 'react'

/**
 * ErrorBoundary — catches render-time errors inside a subtree and shows the
 * error text instead of a blank page. Wrap fragile components (e.g. the
 * camera-driven scanner) with this so the user always sees what went wrong.
 */
export default class ErrorBoundary extends Component {
  constructor(props) {
    super(props)
    this.state = { error: null }
  }

  static getDerivedStateFromError(error) {
    // The html5-qrcode library throws "Cannot stop, scanner is not running or
    // paused" when teardown races with startup. This is harmless — the camera
    // is already gone. Don't surface it as a real error.
    const msg = String(error?.message || error)
    if (msg.includes('Cannot stop, scanner is not running')) {
      return { error: null }
    }
    return { error }
  }

  componentDidCatch(error, info) {
    // eslint-disable-next-line no-console
    console.error('ErrorBoundary caught:', error, info)
  }

  render() {
    if (this.state.error) {
      return (
        <div
          style={{
            padding: 30,
            color: '#fbbf24',
            textAlign: 'center',
            maxWidth: 520,
            margin: '40px auto',
            border: '1px solid #2a2f36',
            borderRadius: 12,
            background: '#15181d',
            fontSize: 14,
            lineHeight: 1.6,
          }}
        >
          <div style={{ fontSize: 18, fontWeight: 700, marginBottom: 10, color: '#fff' }}>
            Something went wrong
          </div>
          <div>{String(this.state.error?.message || this.state.error)}</div>
          <button
            className="btn ghost sm"
            onClick={() => this.setState({ error: null })}
            style={{ marginTop: 16, color: '#e8eaed', borderColor: '#2a2f36' }}
          >
            Try again
          </button>
        </div>
      )
    }
    return this.props.children
  }
}
