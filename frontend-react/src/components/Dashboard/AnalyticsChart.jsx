import {
  ResponsiveContainer, AreaChart, Area, XAxis, YAxis, Tooltip, CartesianGrid,
} from 'recharts'

/**
 * AnalyticsChart — live operations telemetry. Plots hourly entry velocity and
 * shows running capacity / attendance stats.
 */
export default function AnalyticsChart({ analytics }) {
  if (!analytics) return null

  const data = (analytics.hourly_velocity || []).map((row) => ({
    hour: row.hour?.slice(11) || row.hour, // HH:00
    checkins: Number(row.count),
  }))

  return (
    <div>
      <div className="grid cols-4" style={{ marginBottom: 18 }}>
        <Stat label="Registrations" value={analytics.total_registrations} />
        <Stat label="Checked In" value={analytics.checked_in} />
        <Stat
          label="Attendance"
          value={`${analytics.attendance_rate ?? 0}%`}
        />
        <Stat
          label="Capacity"
          value={analytics.capacity_rate != null ? `${analytics.capacity_rate}%` : '∞'}
        />
      </div>

      <div className="card">
        <h3 style={{ marginBottom: 14 }}>Hourly entry velocity</h3>
        {data.length === 0 ? (
          <p className="muted">No check-ins recorded yet.</p>
        ) : (
          <ResponsiveContainer width="100%" height={260}>
            <AreaChart data={data} margin={{ left: -18, right: 8, top: 8 }}>
              <defs>
                <linearGradient id="g" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stopColor="#EC2024" stopOpacity={0.35} />
                  <stop offset="100%" stopColor="#EC2024" stopOpacity={0} />
                </linearGradient>
              </defs>
              <CartesianGrid strokeDasharray="3 3" stroke="#eef0f3" vertical={false} />
              <XAxis dataKey="hour" tick={{ fontSize: 12, fill: '#6b7280' }} />
              <YAxis allowDecimals={false} tick={{ fontSize: 12, fill: '#6b7280' }} />
              <Tooltip />
              <Area type="monotone" dataKey="checkins" stroke="#EC2024" strokeWidth={2} fill="url(#g)" />
            </AreaChart>
          </ResponsiveContainer>
        )}
      </div>
    </div>
  )
}

function Stat({ label, value }) {
  return (
    <div className="card stat">
      <span className="value">{value ?? 0}</span>
      <span className="label">{label}</span>
    </div>
  )
}
