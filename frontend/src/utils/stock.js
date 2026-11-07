import { formatQuantity } from '@/utils/format'
import { billTotals, toPaise } from '@/utils/billing'
import { costPerPack } from '@/utils/packs'

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
// A line of a delivery for one size found in the search. `stock` is in loose units and `cost`
// is the size's average cost per loose unit; `unit`, `unit_size` and `price` are the line's own.
// A product bought in packs starts in packs (10 coils at the coil's price); enterAs() switches.
export function stockLine(product, variant) {
  const line = {
    key: nextKey++,
    variant_id: variant.id,
    product_name: product.name,
    variant_name: variant.name,
    loose_unit: product.unit,
    pack: product.pack ?? null,
    entered_as: 'loose',
    unit: product.unit,
    unit_size: 1,
    stock: variant.stock_quantity,
    cost: variant.purchase_price,
    quantity: 1,
    price: roundMoney(variant.purchase_price),
  }

  enterAs(line, 'pack')

  return line
}

function roundMoney(value) {
  return value === null || value === undefined ? '' : Math.round(Number(value) * 100) / 100
}

// Switch a line between packs and loose: its unit and price follow, and it starts at 1.
export function enterAs(line, mode) {
  if (!line.pack || line.entered_as === mode) {
    return
  }

  const inPacks = mode === 'pack'

  line.entered_as = mode
  line.unit = inPacks ? { ...line.pack.unit, allow_decimal: false } : line.loose_unit
  line.unit_size = inPacks ? line.pack.size : 1
  line.price = inPacks
    ? line.cost === null || line.cost === undefined
      ? ''
      : costPerPack(line.cost, line.pack.size)
    : roundMoney(line.cost)
  line.quantity = 1
}

// The size's average cost per one of the line's unit: per coil for a line in coils.
export function lineCost(line) {
  return line.cost === null || line.cost === undefined
    ? null
    : roundMoney(Number(line.cost) * (line.unit_size ?? 1))
}

// The purchase price after this line comes in, worked out the way the server does: the old
// and new stock averaged by quantity, or simply the new price when there is no stock to
// average with.
// The average cost after this delivery, per one of the line's unit (per coil for coils).
export function costAfter(line) {
  const size = line.unit_size ?? 1
  const stock = Number(line.stock) / size
  const cost = lineCost(line)
  const quantity = Number(line.quantity)

  if (!(quantity > 0)) {
    return cost
  }

  if (stock <= 0 || cost === null) {
    return toPaise(line.price) / 100
  }

  return (
    Math.round((stock * toPaise(cost) + quantity * toPaise(line.price)) / (stock + quantity)) / 100
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
      entered_as: line.entered_as ?? 'loose',
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
