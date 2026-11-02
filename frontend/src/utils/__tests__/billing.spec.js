import { describe, it, expect } from 'vitest'

import {
  billLine,
  billMessage,
  billPayload,
  billPayments,
  billTotals,
  dueReminderMessage,
  duesPayment,
  formatMobile,
  isShortOfStock,
  isValidMobile,
  lineTotal,
  normalizeMobile,
  paidByMethod,
  paymentReceiptMessage,
  paymentRow,
  statusClass,
  toPaise,
  whatsappLink,
} from '../billing'

const kg = { id: 2, name: 'Kilogram', short_name: 'kg', allow_decimal: true }
const nails = {
  id: 7,
  name: 'Wire Nails',
  unit: kg,
  variants: [{ id: 70, name: '2 inch', selling_price: 120, stock_quantity: 25 }],
}

describe('mobile numbers', () => {
  it.each([
    ['9876543210', '9876543210'],
    ['98765 43210', '9876543210'],
    ['+91 98765-43210', '9876543210'],
    ['919876543210', '9876543210'],
    ['09876543210', '9876543210'],
  ])('reads %s as %s', (typed, saved) => {
    expect(normalizeMobile(typed)).toBe(saved)
  })

  it('accepts only Indian mobile numbers', () => {
    expect(isValidMobile('+91 98765 43210')).toBe(true)
    expect(isValidMobile('5876543210')).toBe(false)
    expect(isValidMobile('98765')).toBe(false)
    expect(isValidMobile('')).toBe(false)
  })

  it('groups the digits the way they are read out', () => {
    expect(formatMobile('9876543210')).toBe('98765 43210')
  })
})

describe('bill lines and totals', () => {
  it('starts a line at one of the size, at its selling price', () => {
    const line = billLine(nails, nails.variants[0])

    expect(line).toMatchObject({
      variant_id: 70,
      product_name: 'Wire Nails',
      variant_name: '2 inch',
      unit: kg,
      list_price: 120,
      price: 120,
      quantity: 1,
      stock: 25,
    })
    expect(billLine(nails, nails.variants[0]).key).not.toBe(line.key)
  })

  it('adds up money in paise', () => {
    expect(toPaise('0.1') + toPaise(0.2)).toBe(30)
    expect(toPaise('')).toBe(0)
    expect(toPaise('abc')).toBe(0)
    expect(lineTotal({ quantity: '1.5', price: '120' })).toBe(180)
    expect(lineTotal({ quantity: 3, price: 0.1 })).toBe(0.3)
    expect(lineTotal({ quantity: 1.333, price: 10 })).toBe(13.33)
    expect(lineTotal({ quantity: '', price: 120 })).toBe(0)
  })

  it('works out what is paid and what stays due', () => {
    const lines = [
      { quantity: 2, price: 350 },
      { quantity: 1.5, price: 120 },
    ]

    expect(billTotals(lines, [{ amount: 500 }, { amount: '' }])).toEqual({
      total: 880,
      paid: 500,
      due: 380,
      overpaid: false,
    })
    expect(billTotals(lines, [{ amount: 900 }])).toEqual({
      total: 880,
      paid: 900,
      due: 0,
      overpaid: true,
    })
  })

  it('warns when billing more than the stock shows', () => {
    expect(isShortOfStock({ quantity: 12, stock: 10 })).toBe(true)
    expect(isShortOfStock({ quantity: '10', stock: 10 })).toBe(false)
  })
})

describe('payments', () => {
  const full = { method: 'upi', reference: ' UPI123 ' }
  const rows = [
    { ...paymentRow('cash'), amount: 200, reference: 'not for cash' },
    { ...paymentRow('bank_transfer'), amount: '300.50', reference: '' },
  ]

  it('pays the whole total at once', () => {
    expect(billPayments('full', { full, rows }, 880)).toEqual([
      { method: 'upi', amount: 880, reference: 'UPI123' },
    ])
  })

  it('leaves out a transaction number for cash', () => {
    const cash = { method: 'cash', reference: 'UPI123' }

    expect(billPayments('full', { full: cash, rows }, 880)[0].reference).toBeNull()
  })

  it('sends each part of a split payment', () => {
    expect(billPayments('split', { full, rows }, 880)).toEqual([
      { method: 'cash', amount: 200, reference: null },
      { method: 'bank_transfer', amount: 300.5, reference: null },
    ])
  })

  it('sends nothing when nothing is paid now, or there is nothing to pay', () => {
    expect(billPayments('none', { full, rows }, 880)).toEqual([])
    expect(billPayments('full', { full, rows }, 0)).toEqual([])
  })

  it('builds a payment towards dues', () => {
    expect(duesPayment({ method: 'upi', amount: '400', reference: ' 1234 ' })).toEqual({
      method: 'upi',
      amount: 400,
      reference: '1234',
    })
  })
})

describe('billPayload', () => {
  it('sends the customer, items and payments as the API expects', () => {
    const line = { ...billLine(nails, nails.variants[0]), quantity: '1.5', price: '110' }
    const payments = [{ method: 'cash', amount: 165, reference: null }]

    expect(
      billPayload({ name: ' Ramesh Kumar ', mobile: '+91 98765-43210' }, [line], payments),
    ).toEqual({
      customer: { name: 'Ramesh Kumar', mobile: '9876543210' },
      items: [{ product_variant_id: 70, quantity: 1.5, price: 110 }],
      payments,
    })
  })

  it('sends a blank price as missing, not as zero', () => {
    const line = { ...billLine(nails, nails.variants[0]), price: '' }

    expect(billPayload({ name: 'A', mobile: '9876543210' }, [line], []).items[0].price).toBeNull()
  })
})

describe('WhatsApp messages', () => {
  const shop = { name: 'Sharma Hardware', address: 'MG Road, Jaipur', phone: '0141 2345678' }
  const bill = {
    bill_number: 'INV-2026-0007',
    created_at: '2026-11-02T10:15:00.000000Z',
    customer: { id: 4, name: 'Ramesh Kumar', mobile: '9876543210' },
    total: 880,
    paid_amount: 500,
    due_amount: 380,
    items: [
      {
        product_name: 'Claw Hammer',
        variant_name: null,
        unit: 'pc',
        quantity: 2,
        price: 350,
        line_total: 700,
      },
      {
        product_name: 'Wire Nails',
        variant_name: '2 inch',
        unit: 'kg',
        quantity: 1.5,
        price: 120,
        line_total: 180,
      },
    ],
    payments: [
      { method: { value: 'cash', label: 'Cash' }, amount: 200 },
      { method: { value: 'upi', label: 'UPI' }, amount: 250 },
      { method: { value: 'cash', label: 'Cash' }, amount: 50 },
    ],
  }

  it('opens a chat with the number, the message typed in', () => {
    expect(whatsappLink('9876543210', 'Total: ₹880 & more')).toBe(
      'https://wa.me/919876543210?text=Total%3A%20%E2%82%B9880%20%26%20more',
    )
  })

  it('totals what was paid each way', () => {
    expect(paidByMethod(bill.payments)).toEqual([
      { label: 'Cash', amount: 250 },
      { label: 'UPI', amount: 250 },
    ])
  })

  it('writes out the bill', () => {
    const message = billMessage(bill, shop)

    expect(message).toMatch(/^\*Sharma Hardware\*\nMG Road, Jaipur · 0141 2345678\n\n/)
    expect(message).toContain('Bill INV-2026-0007')
    expect(message).toContain('To: Ramesh Kumar')
    expect(message).toContain('• Claw Hammer: 2 pc × ₹350.00 = ₹700.00')
    expect(message).toContain('• Wire Nails (2 inch): 1.5 kg × ₹120.00 = ₹180.00')
    expect(message).toContain('*Total: ₹880.00*')
    expect(message).toContain('Paid: ₹500.00 (Cash ₹250.00 + UPI ₹250.00)')
    expect(message).toContain('*Due: ₹380.00*')
  })

  it('says when a bill is paid in full, and skips contact details that are not set', () => {
    const message = billMessage(
      { ...bill, paid_amount: 880, due_amount: 0 },
      { name: 'Sharma Hardware', address: null, phone: '' },
    )

    expect(message).toMatch(/^\*Sharma Hardware\*\n\nBill/)
    expect(message).toContain('Paid in full.')
    expect(message).not.toContain('Due')
  })

  it('leaves out the paid line when nothing is paid', () => {
    const message = billMessage({ ...bill, paid_amount: 0, due_amount: 880, payments: [] }, shop)

    expect(message).not.toContain('Paid')
    expect(message).toContain('*Due: ₹880.00*')
  })

  it('reminds a customer of what they owe, oldest bill first', () => {
    const customer = {
      name: 'Ramesh Kumar',
      total_due: 500,
      bills: [
        { bill_number: 'INV-2026-0009', created_at: '2026-11-04T06:30:00Z', due_amount: 300 },
        { bill_number: 'INV-2026-0008', created_at: '2026-11-03T06:30:00Z', due_amount: 0 },
        { bill_number: 'INV-2026-0007', created_at: '2026-11-02T06:30:00Z', due_amount: 200 },
      ],
    }

    const message = dueReminderMessage(customer, shop)

    expect(message).toMatch(/^Namaste Ramesh Kumar,/)
    expect(message).toContain('₹500.00 is due on your account')
    expect(message).toContain('• INV-2026-0007 of 2 Nov 2026: ₹200.00')
    expect(message.indexOf('INV-2026-0007')).toBeLessThan(message.indexOf('INV-2026-0009'))
    expect(message).not.toContain('INV-2026-0008')
  })

  it('confirms a payment with what is still due', () => {
    const customer = { name: 'Ramesh Kumar', total_due: 100 }

    const message = paymentReceiptMessage(customer, { method: 'upi', amount: 400 }, shop)

    expect(message).toContain('Received ₹400.00 by UPI from Ramesh Kumar')
    expect(message).toContain('Still due: ₹100.00')
    expect(
      paymentReceiptMessage({ ...customer, total_due: 0 }, { method: 'cash', amount: 100 }, shop),
    ).toContain('Nothing is due on your account now.')
  })
})

describe('statusClass', () => {
  it('colours each payment status', () => {
    expect(statusClass({ value: 'paid' })).toBe('badge-active')
    expect(statusClass({ value: 'partly_paid' })).toBe('badge-warning')
    expect(statusClass({ value: 'unpaid' })).toBe('badge-danger')
  })
})
