/**
 * Button — shared button with variant + loading support.
 * variants: 'primary' | 'ghost' | 'dark'
 */
export default function Button({
  children,
  variant = 'primary',
  size,
  loading = false,
  disabled = false,
  type = 'button',
  ...rest
}) {
  const cls = ['btn', variant, size === 'sm' ? 'sm' : ''].filter(Boolean).join(' ')
  return (
    <button className={cls} type={type} disabled={disabled || loading} {...rest}>
      {loading && <span className="spinner" style={{ width: 16, height: 16, borderWidth: 2 }} />}
      {children}
    </button>
  )
}
