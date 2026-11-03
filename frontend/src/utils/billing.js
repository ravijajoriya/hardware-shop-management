import { formatDate, formatDateTime, formatMoney, formatQuantity } from '@/utils/format'

// How a customer can pay. Whatever is not paid stays due (udhaar) on the bill.
export const PAYMENT_METHODS = [
  { value: 'cash', label: 'Cash' },
  { value: 'upi', label: 'UPI' },
  { value: 'bank_transfer', label: 'Bank transfer' },
]

export function methodLabel(value) {
  return PAYMENT_METHODS.find((method) => method.value === value)?.label ?? value
}

// How much of the bill was paid while making it.
export const PAYMENT_MODES = [
  { value: 'full', label: 'Paid in full' },
  { value: 'split', label: 'Split or part paid' },
  { value: 'none', label: 'Nothing paid now' },
]

export const BILL_STATUSES = [
  { value: 'paid', label: 'Paid' },
  { value: 'partly_paid', label: 'Partly paid' },
  { value: 'unpaid', label: 'Unpaid' },
  { value: 'returned', label: 'Fully returned' },
  { value: 'cancelled', label: 'Cancelled' },
]

// More ways to narrow and order the bills list.
export const BILL_CHANGES = [
  { value: 'edited', label: 'Edited' },
  { value: 'returned', label: 'Items returned' },
]

// Bills still owing money this many days after they were made.
export const OVERDUE_DAYS = [30, 60, 90]

export const BILL_SORTS = [
  { value: '', label: 'Newest first' },
  { value: 'oldest', label: 'Oldest first' },
  { value: 'total', label: 'Biggest total' },
  { value: 'due', label: 'Biggest due' },
]

// The badge colour for a bill's status: green when paid, amber when part is due, red when all
// is, and muted when nothing is owed because the items came back or the bill was cancelled.
export function statusClass(status) {
  return {
    paid: 'badge-active',
    partly_paid: 'badge-warning',
    unpaid: 'badge-danger',
    returned: 'badge-muted',
    cancelled: 'badge-muted',
  }[status.value]
}

let nextKey = 1

// --- Mobile numbers ---

// The 10 digits the API saves, from a number typed with +91, a leading 0, spaces or dashes.
export function normalizeMobile(value) {
  const digits = String(value ?? '').replace(/\D/g, '')

  if (digits.length === 12 && digits.startsWith('91')) {
    return digits.slice(2)
  }

  if (digits.length === 11 && digits.startsWith('0')) {
    return digits.slice(1)
  }

  return digits
}

export function isValidMobile(value) {
  return /^[6-9]\d{9}$/.test(normalizeMobile(value))
}

// 98765 43210, the way the number is read out.
export function formatMobile(mobile) {
  return mobile ? `${mobile.slice(0, 5)} ${mobile.slice(5)}` : ''
}

// --- Amounts ---

// Money is added up in paise, so ₹0.10 + ₹0.20 comes to ₹0.30 and not ₹0.30000000000000004.
export function toPaise(value) {
  const amount = Number(value)

  return Number.isFinite(amount) ? Math.round(amount * 100) : 0
}

function numberOrNull(value) {
  const text = String(value ?? '').trim()

  return text === '' ? null : Number(text)
}

function blankToNull(value) {
  const text = String(value ?? '').trim()

  return text === '' ? null : text
}

// --- The bill being made ---

// A line on the bill for one size of a product found in the search. `key` only exists in the
// browser, to keep rows apart; the price starts at the product's selling price and can be changed.
export function billLine(product, variant) {
  return {
    key: nextKey++,
    variant_id: variant.id,
    product_id: product.id,
    product_name: product.name,
    variant_name: variant.name,
    unit: product.unit,
    list_price: variant.selling_price,
    stock: variant.stock_quantity,
    quantity: 1,
    price: variant.selling_price,
  }
}

function linePaise(line) {
  const quantity = Number(line.quantity)

  return Number.isFinite(quantity) && quantity > 0 ? Math.round(quantity * toPaise(line.price)) : 0
}

export function lineTotal(line) {
  return linePaise(line) / 100
}

// Selling more than the stock shows is allowed, since the count may be off, but worth a warning.
// A line whose product has been deleted has no stock to compare with.
export function isShortOfStock(line) {
  return (
    line.stock !== null && line.stock !== undefined && Number(line.quantity) > Number(line.stock)
  )
}

// A payment row for a split payment; `key` only exists in the browser.
export function paymentRow(method = 'cash', amount = '') {
  return { key: nextKey++, method, amount, reference: '' }
}

// A transaction number only means something for UPI and bank payments.
function referenceFor(method, reference) {
  return method === 'cash' ? null : blankToNull(reference)
}

// The payments to send: one for the whole total when paid in full, each row when split,
// and none when nothing is paid now (the whole bill is then due).
export function billPayments(mode, { full, rows }, total) {
  if (mode === 'full') {
    return toPaise(total) > 0
      ? [
          {
            method: full.method,
            amount: total,
            reference: referenceFor(full.method, full.reference),
          },
        ]
      : []
  }

  if (mode === 'split') {
    return rows.map((row) => ({
      method: row.method,
      amount: numberOrNull(row.amount),
      reference: referenceFor(row.method, row.reference),
    }))
  }

  return []
}

// The body the API expects for a payment towards a customer's dues.
export function duesPayment(form) {
  return {
    method: form.method,
    amount: numberOrNull(form.amount),
    reference: referenceFor(form.method, form.reference),
  }
}

// The bill's total, what the payments add up to and what is left due.
export function billTotals(lines, payments) {
  const total = lines.reduce((sum, line) => sum + linePaise(line), 0)
  const paid = payments.reduce((sum, payment) => sum + toPaise(payment.amount), 0)

  return {
    total: total / 100,
    paid: paid / 100,
    due: Math.max(total - paid, 0) / 100,
    overpaid: paid > total,
  }
}

// The body the API expects when making a bill.
export function billPayload(customer, lines, payments) {
  return {
    customer: {
      name: customer.name.trim(),
      mobile: normalizeMobile(customer.mobile),
      address: blankToNull(customer.address),
    },
    items: lines.map((line) => ({
      product_variant_id: line.variant_id,
      quantity: numberOrNull(line.quantity),
      price: numberOrNull(line.price),
    })),
    payments,
  }
}

// --- Changing a saved bill ---

// A saved bill line in the form, to edit. Its own items are already off the shelf, so they
// count as in stock for it; `original` is what the bill said before the edit.
export function editLine(item) {
  return {
    key: nextKey++,
    id: item.id,
    variant_id: item.product_variant_id,
    product_name: item.product_name,
    variant_name: item.variant_name,
    unit: {
      short_name: item.unit,
      name: item.current?.unit_name ?? item.unit,
      allow_decimal: item.current?.allow_decimal ?? true,
    },
    list_price: item.list_price,
    stock: item.current
      ? Math.round((item.current.stock_quantity + item.quantity) * 1000) / 1000
      : null,
    quantity: item.quantity,
    price: item.price,
    original: { quantity: item.quantity, price: item.price },
  }
}

// The body the API expects when saving an edited bill. A refund goes with it when the new
// total is less than what was paid.
export function editPayload(customer, lines, refund = null) {
  const { customer: details, items } = billPayload(customer, lines, [])

  return {
    customer: details,
    items: items.map((item, index) => (lines[index].id ? { id: lines[index].id, ...item } : item)),
    refund: refund
      ? { method: refund.method, reference: referenceFor(refund.method, refund.reference) }
      : null,
  }
}

// What the customer has paid on the bill and not had back.
export function netPaid(bill) {
  return (toPaise(bill.paid_amount) - toPaise(bill.refunded_amount)) / 100
}

// What would have to be given back if the bill's total became `total`.
export function refundAfterEdit(bill, total) {
  const payable = toPaise(total) - toPaise(bill.returned_amount)

  return Math.max(toPaise(netPaid(bill)) - payable, 0) / 100
}

// --- Returns and cancelling ---

// How much of a line is still with the customer, and so can come back.
export function returnableQuantity(item) {
  return Math.round((item.quantity - item.returned_quantity) * 1000) / 1000
}

// A line of the return form; nothing is coming back until a quantity is typed.
export function returnRow(item) {
  return { key: nextKey++, item, quantity: '' }
}

// What the returned items were worth on the bill.
export function returnTotal(rows) {
  return billTotals(
    rows.map((row) => ({ quantity: row.quantity, price: row.item.price })),
    [],
  ).total
}

// How a return of `amount` is settled: first off what is still due, the rest given back.
export function returnSettlement(bill, amount) {
  const worth = toPaise(amount)
  const offDue = Math.min(worth, toPaise(bill.due_amount))

  return { dueReduced: offDue / 100, refund: (worth - offDue) / 100 }
}

function refundPayload(refund) {
  return refund?.method
    ? { method: refund.method, reference: referenceFor(refund.method, refund.reference) }
    : null
}

// The body the API expects when items come back: only the lines with a quantity.
export function returnPayload(rows, note, refund) {
  return {
    items: rows
      .filter((row) => numberOrNull(row.quantity) > 0)
      .map((row) => ({ bill_item_id: row.item.id, quantity: numberOrNull(row.quantity) })),
    note: blankToNull(note),
    refund: refundPayload(refund),
  }
}

export function cancelPayload(reason, refund) {
  return { reason: reason.trim(), refund: refundPayload(refund) }
}

// --- WhatsApp ---

// A wa.me link opens WhatsApp (the app, or WhatsApp Web) with the message typed in, ready to send.
export function whatsappLink(mobile, text) {
  return `https://wa.me/91${mobile}?text=${encodeURIComponent(text)}`
}

function shopHeader(shop) {
  const contact = [shop.address, shop.phone].filter(Boolean).join(' · ')

  return contact ? [`*${shop.name}*`, contact] : [`*${shop.name}*`]
}

export function itemName(item) {
  return item.variant_name ? `${item.product_name} (${item.variant_name})` : item.product_name
}

// A saved bill keeps only the unit's short name, such as "kg".
export function itemQuantity(item) {
  return formatQuantity(item.quantity, { short_name: item.unit })
}

// "Cash ₹200.00 + UPI ₹300.00": what was paid each way, in the order it was first paid.
export function paidByMethod(payments) {
  const totals = new Map()

  for (const payment of payments) {
    const { label } = payment.method
    totals.set(label, (totals.get(label) ?? 0) + toPaise(payment.amount))
  }

  return [...totals].map(([label, paise]) => ({ label, amount: paise / 100 }))
}

// The bill as a WhatsApp message.
export function billMessage(bill, shop) {
  const lines = [
    ...shopHeader(shop),
    '',
    `Bill ${bill.bill_number}`,
    formatDateTime(bill.created_at),
    `To: ${bill.customer.name}`,
    '',
  ]

  if (bill.status?.value === 'cancelled') {
    lines.push('*This bill has been cancelled.*', '')
  }

  for (const item of bill.items) {
    lines.push(
      `• ${itemName(item)}: ${itemQuantity(item)} × ${formatMoney(item.price)} = ${formatMoney(item.line_total)}`,
    )
  }

  lines.push('', `*Total: ${formatMoney(bill.total)}*`)

  if (bill.returned_amount > 0) {
    lines.push(`Returned: −${formatMoney(bill.returned_amount)}`)
  }

  if (bill.paid_amount > 0) {
    const ways = paidByMethod(bill.payments)
      .map((way) => `${way.label} ${formatMoney(way.amount)}`)
      .join(' + ')

    lines.push(`Paid: ${formatMoney(bill.paid_amount)} (${ways})`)
  }

  if (bill.refunded_amount > 0) {
    lines.push(`Given back: ${formatMoney(bill.refunded_amount)}`)
  }

  lines.push(bill.due_amount > 0 ? `*Due: ${formatMoney(bill.due_amount)}*` : 'Paid in full.')

  // WhatsApp turns the address into a link the customer taps to open or save the PDF.
  if (bill.share_url) {
    lines.push('', `PDF of your bill: ${bill.share_url}`)
  }

  lines.push('', 'Thank you for shopping with us!')

  return lines.join('\n')
}

// The few words that go with the bill's PDF when it is shared.
export function billCaption(bill, shop) {
  const due = bill.due_amount > 0 ? `${formatMoney(bill.due_amount)} due` : 'paid in full'

  return `Bill ${bill.bill_number} from ${shop.name}: ${formatMoney(bill.total)}, ${due}. Thank you!`
}

// A polite reminder of what the customer owes, bill by bill, oldest first.
export function dueReminderMessage(customer, shop) {
  const dueBills = customer.bills.filter((bill) => bill.due_amount > 0).reverse()

  return [
    `Namaste ${customer.name},`,
    '',
    `This is a reminder from ${shop.name} that ${formatMoney(customer.total_due)} is due on your account:`,
    ...dueBills.map(
      (bill) =>
        `• ${bill.bill_number} of ${formatDate(bill.created_at)}: ${formatMoney(bill.due_amount)}`,
    ),
    '',
    'Please pay at the shop or by UPI when convenient. Thank you!',
    '',
    ...shopHeader(shop),
  ].join('\n')
}

// A receipt for a payment towards one bill, with what that bill still owes and its PDF link.
export function billPaymentReceiptMessage(bill, payment, shop) {
  return [
    ...shopHeader(shop),
    '',
    `Received ${formatMoney(payment.amount)} by ${methodLabel(payment.method)} from ${bill.customer.name} on ${formatDate(new Date())} for bill ${bill.bill_number}. Thank you!`,
    bill.due_amount > 0
      ? `Still due on this bill: ${formatMoney(bill.due_amount)}`
      : 'This bill is now paid in full.',
    ...(bill.share_url ? ['', `PDF of your bill: ${bill.share_url}`] : []),
  ].join('\n')
}

// A receipt for a payment towards dues, with what is still owed.
export function paymentReceiptMessage(customer, payment, shop) {
  return [
    ...shopHeader(shop),
    '',
    `Received ${formatMoney(payment.amount)} by ${methodLabel(payment.method)} from ${customer.name} on ${formatDate(new Date())}. Thank you!`,
    customer.total_due > 0
      ? `Still due: ${formatMoney(customer.total_due)}`
      : 'Nothing is due on your account now.',
  ].join('\n')
}
