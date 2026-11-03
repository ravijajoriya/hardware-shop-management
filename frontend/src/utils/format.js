const money = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' })
const wholeMoney = new Intl.NumberFormat('en-IN', {
  style: 'currency',
  currency: 'INR',
  maximumFractionDigits: 0,
})

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

// Paise appear only when there are some: ₹1,500 and ₹320.50. Display only; amounts keep
// their paise everywhere else.
export function formatMoney(value) {
  const paise = Math.round(Number(value ?? 0) * 100)

  return (paise % 100 === 0 ? wholeMoney : money).format(paise / 100)
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
