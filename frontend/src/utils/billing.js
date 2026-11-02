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
]

// The badge colour for a bill's status: green when paid, amber when part is due, red when all is.
export function statusClass(status) {
  return {
    paid: 'badge-active',
    partly_paid: 'badge-warning',
    unpaid: 'badge-danger',
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
export function isShortOfStock(line) {
  return Number(line.quantity) > Number(line.stock)
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
    customer: { name: customer.name.trim(), mobile: normalizeMobile(customer.mobile) },
    items: lines.map((line) => ({
      product_variant_id: line.variant_id,
      quantity: numberOrNull(line.quantity),
      price: numberOrNull(line.price),
    })),
    payments,
  }
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

  for (const item of bill.items) {
    lines.push(
      `• ${itemName(item)}: ${itemQuantity(item)} × ${formatMoney(item.price)} = ${formatMoney(item.line_total)}`,
    )
  }

  lines.push('', `*Total: ${formatMoney(bill.total)}*`)

  if (bill.paid_amount > 0) {
    const ways = paidByMethod(bill.payments)
      .map((way) => `${way.label} ${formatMoney(way.amount)}`)
      .join(' + ')

    lines.push(`Paid: ${formatMoney(bill.paid_amount)} (${ways})`)
  }

  lines.push(bill.due_amount > 0 ? `*Due: ${formatMoney(bill.due_amount)}*` : 'Paid in full.')
  lines.push('', 'Thank you for shopping with us!')

  return lines.join('\n')
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
