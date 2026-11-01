const money = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' })

// Quantities keep up to three decimals and drop trailing zeros: 12.5 kg, 3 pc.
const quantity = new Intl.NumberFormat('en-IN', { maximumFractionDigits: 3 })

export function formatMoney(value) {
  return money.format(value ?? 0)
}

export function formatQuantity(value, unit) {
  const amount = quantity.format(value ?? 0)

  return unit ? `${amount} ${unit.short_name}` : amount
}
