import { useEffect, useRef, useState } from 'react'
import { Html5Qrcode } from 'html5-qrcode'

/**
 * CameraFeed — wraps html5-qrcode with React-compatibility hardening.
 *
 * Critical implementation notes:
 *  • html5-qrcode mutates its target node directly (appends <video>, <canvas>,
 *    etc). React must NEVER touch that node's children during unmount, or it
 *    throws "Failed to execute removeChild". The fix is:
 *      1. Mount a stable wrapper React owns.
 *      2. Inside the wrapper, on mount, create a plain DOM div with a unique
 *         id via vanilla JS — React doesn't track it.
 *      3. Hand that div to html5-qrcode.
 *      4. On unmount, manually empty the wrapper before React tries.
 *  • A unique id (timestamp + counter) prevents collisions between mount
 *    cycles, even when StrictMode would double-mount.
 */

let counter = 0

export default function CameraFeed({ onScan, paused }) {
  const wrapperRef = useRef(null)
  const scannerRef = useRef(null)
  const startedRef = useRef(false)
  const lastRef = useRef({ text: null, at: 0 })
  const [status, setStatus] = useState('starting')
  const [errMsg, setErrMsg] = useState('')

  // Mount: create the camera target div and start the scanner. Cleanup tears
  // down the camera and detaches the target div before React unmounts.
  useEffect(() => {
    const wrapper = wrapperRef.current
    if (!wrapper) return

    // Create the camera target as a plain DOM node React doesn't manage.
    const elementId = 'reader-' + Date.now() + '-' + ++counter
    const target = document.createElement('div')
    target.id = elementId
    target.style.width = '100%'
    target.style.height = '100%'
    wrapper.appendChild(target)

    let cancelled = false
    let scanner

    const start = async () => {
      if (!navigator.mediaDevices?.getUserMedia) {
        setStatus('error')
        setErrMsg('Camera access requires HTTPS or localhost. From a phone on the WiFi, the page must use https:// — try running `npm run dev:https` and visit the https:// URL.')
        return
      }

      try {
        scanner = new Html5Qrcode(elementId, { verbose: false })
        scannerRef.current = scanner
      } catch (e) {
        if (!cancelled) {
          setStatus('error')
          setErrMsg('Failed to initialise scanner: ' + e.message)
        }
        return
      }

      const config = { fps: 12, qrbox: { width: 240, height: 240 }, aspectRatio: 1.0 }
      const handle = (decodedText) => {
        const now = Date.now()
        if (lastRef.current.text === decodedText && now - lastRef.current.at < 2500) return
        lastRef.current = { text: decodedText, at: now }
        onScan?.(decodedText)
      }

      try {
        // Prefer facingMode for mobile reliability.
        await scanner.start({ facingMode: 'environment' }, config, handle, () => {})
      } catch (e1) {
        try {
          // Fall back to deviceId if facingMode wasn't accepted.
          const cameras = await Html5Qrcode.getCameras()
          if (!cameras.length) throw new Error('No cameras found')
          const back = cameras.find((c) => /back|rear|environment/i.test(c.label)) || cameras[0]
          await scanner.start({ deviceId: { exact: back.id } }, config, handle, () => {})
        } catch (e2) {
          if (!cancelled) {
            setStatus('error')
            setErrMsg('Camera failed to start: ' + (e2.message || e1.message || 'unknown'))
          }
          // Ensure the scanner is fully torn down on a failed start.
          try { await scanner.clear() } catch { /* ignore */ }
          scannerRef.current = null
          return
        }
      }

      if (cancelled) {
        // Component was unmounted while we were starting. Tear down silently.
        try { await scanner.stop() } catch { /* ignore */ }
        try { await scanner.clear() } catch { /* ignore */ }
        scannerRef.current = null
        return
      }
      startedRef.current = true
      setStatus('running')
    }

    start()

    return () => {
      cancelled = true
      const s = scannerRef.current
      scannerRef.current = null

      const teardown = async () => {
        if (s && startedRef.current) {
          try { await s.stop() } catch { /* already gone */ }
        }
        if (s) {
          try { await s.clear() } catch { /* already cleared */ }
        }
        // After html5-qrcode is done with its DOM nodes, manually detach the
        // camera target. We control this div ourselves — React never tracked
        // it, so there's no removeChild() mismatch on unmount.
        if (target.parentNode === wrapper) {
          try { wrapper.removeChild(target) } catch { /* ignore */ }
        }
      }
      teardown()
      startedRef.current = false
    }
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  // Pause / resume scanning when an overlay is shown.
  useEffect(() => {
    const s = scannerRef.current
    if (!s || status !== 'running') return
    try {
      if (paused) s.pause(true)
      else s.resume()
    } catch { /* not in a pausable state */ }
  }, [paused, status])

  const toggleTorch = async () => {
    try {
      const caps = scannerRef.current?.getRunningTrackCapabilities?.()
      if (caps?.torch) {
        await scannerRef.current.applyVideoConstraints({ advanced: [{ torch: true }] })
      }
    } catch { /* not supported */ }
  }

  return (
    <div style={{ width: '100%', maxWidth: 460, margin: '0 auto' }}>
      <div
        ref={wrapperRef}
        style={{
          position: 'relative',
          width: '100%',
          minHeight: 280,
          background: '#000',
          borderRadius: 16,
          overflow: 'hidden',
        }}
      >
        {/* Overlay messages render above the camera target. */}
        {status !== 'running' && (
          <div
            style={{
              position: 'absolute',
              inset: 0,
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              color: status === 'error' ? '#fbbf24' : '#aaa',
              fontSize: 13,
              padding: 20,
              textAlign: 'center',
              lineHeight: 1.5,
              zIndex: 2,
              pointerEvents: 'none',
            }}
          >
            {status === 'starting' ? 'Starting camera…' : errMsg}
          </div>
        )}
      </div>

      {status === 'running' && (
        <div className="row" style={{ justifyContent: 'center', marginTop: 12 }}>
          <button
            className="btn ghost sm"
            onClick={toggleTorch}
            style={{ color: '#e8eaed', borderColor: '#2a2f36' }}
          >
            🔦 Flashlight
          </button>
        </div>
      )}
    </div>
  )
}
