const money = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' })

// Quantities keep up to three decimals and drop trailing zeros: 12.5 kg, 3 pc.
const quantity = new Intl.NumberFormat('en-IN', { maximumFractionDigits: 3 })

// 2 Nov 2026, and 2 Nov 2026, 3:45 pm, in the device's own time zone.
const date = new Intl.DateTimeFormat('en-IN', { day: 'numeric', month: 'short', year: 'numeric' })
const dateTime = new Intl.DateTimeFormat('en-IN', {
  day: 'numeric',
  month: 'short',
  year: 'numeric',
  hour: 'numeric',
  minute: '2-digit',
})

export function formatMoney(value) {
  return money.format(value ?? 0)
}

export function formatQuantity(value, unit) {
  const amount = quantity.format(value ?? 0)

  return unit ? `${amount} ${unit.short_name}` : amount
}

export function formatDate(value) {
  return value ? date.format(new Date(value)) : ''
}

export function formatDateTime(value) {
  return value ? dateTime.format(new Date(value)) : ''
}
