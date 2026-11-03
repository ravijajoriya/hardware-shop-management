import { formatQuantity } from '@/utils/format'
import { billTotals, toPaise } from '@/utils/billing'

// Why a stock count is set by hand.
export const CORRECTION_REASONS = [
  { value: 'counted', label: 'Counted again' },
  { value: 'damaged', label: 'Damaged or lost' },
  { value: 'customer_return', label: 'Returned by a customer' },
  { value: 'supplier_return', label: 'Sent back to the supplier' },
  { value: 'other', label: 'Other' },
]

let nextKey = 1

function blankToNull(value) {
  const text = String(value ?? '').trim()

  return text === '' ? null : text
}

function numberOrNull(value) {
  const text = String(value ?? '').trim()

  return text === '' ? null : Number(text)
}

// A date as the API takes it (2026-11-03), by this device's calendar.
export function isoDate(date = new Date()) {
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')

  return `${date.getFullYear()}-${month}-${day}`
}

// A line on the delivery for one size found in the search. It starts at 1, at what the size
// cost last time; `stock` and `cost` are what it has now, for the average cost preview.
export function stockLine(product, variant) {
  return {
    key: nextKey++,
    variant_id: variant.id,
    product_name: product.name,
    variant_name: variant.name,
    unit: product.unit,
    stock: variant.stock_quantity,
    cost: variant.purchase_price,
    quantity: 1,
    price: variant.purchase_price,
  }
}

// The purchase price after this line comes in, worked out the way the server does: the old
// and new stock averaged by quantity, or simply the new price when there is no stock to
// average with.
export function costAfter(line) {
  const stock = Number(line.stock)
  const quantity = Number(line.quantity)

  if (!(quantity > 0)) {
    return line.cost
  }

  if (stock <= 0 || line.cost === null || line.cost === undefined) {
    return toPaise(line.price) / 100
  }

  return (
    Math.round((stock * toPaise(line.cost) + quantity * toPaise(line.price)) / (stock + quantity)) /
    100
  )
}

export function entryTotal(lines) {
  return billTotals(lines, []).total
}

// The body the API expects when logging a delivery.
export function entryPayload(form, lines) {
  return {
    supplier_name: blankToNull(form.supplier_name),
    supplier_bill_number: blankToNull(form.supplier_bill_number),
    received_on: form.received_on,
    note: blankToNull(form.note),
    items: lines.map((line) => ({
      product_variant_id: line.variant_id,
      quantity: numberOrNull(line.quantity),
      purchase_price: numberOrNull(line.price),
    })),
  }
}

export function correctionPayload(form) {
  return {
    quantity: numberOrNull(form.quantity),
    reason: form.reason,
    note: blankToNull(form.note),
  }
}

// "+20 pc" or "−2.5 kg": a change in stock with its sign.
export function stockChange(change, unit) {
  const amount = formatQuantity(Math.abs(change), { short_name: unit })

  return change < 0 ? `−${amount}` : `+${amount}`
}
