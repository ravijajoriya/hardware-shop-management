import { describe, it, expect } from 'vitest'

import {
  billCaption,
  cancelPayload,
  editLine,
  editPayload,
  netPaid,
  refundAfterEdit,
  returnPayload,
  returnRow,
  returnSettlement,
  returnTotal,
  returnableQuantity,
  billLine,
  billMessage,
  billPayload,
  billPaymentReceiptMessage,
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

    const customer = { name: ' Ramesh Kumar ', mobile: '+91 98765-43210', address: ' 12, MI Road ' }

    expect(billPayload(customer, [line], payments)).toEqual({
      customer: { name: 'Ramesh Kumar', mobile: '9876543210', address: '12, MI Road' },
      items: [{ product_variant_id: 70, quantity: 1.5, price: 110 }],
      payments,
    })
  })

  it('sends a blank address as none', () => {
    const line = billLine(nails, nails.variants[0])
    const customer = { name: 'Ramesh Kumar', mobile: '9876543210', address: '   ' }

    expect(billPayload(customer, [line], []).customer.address).toBeNull()
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
    expect(message).toContain('• Claw Hammer: 2 pc × ₹350 = ₹700')
    expect(message).toContain('• Wire Nails (2 inch): 1.5 kg × ₹120 = ₹180')
    expect(message).toContain('*Total: ₹880*')
    expect(message).toContain('Paid: ₹500 (Cash ₹250 + UPI ₹250)')
    expect(message).toContain('*Due: ₹380*')
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

  it('adds the link to the bill’s PDF when there is one', () => {
    const url = 'https://shop.example/bill/Ab3dEf6hIj9kLm2nOp5qRs8tUv1wXy4zAb7cDe0f'

    expect(billMessage({ ...bill, share_url: url }, shop)).toContain(
      `*Due: ₹380*\n\nPDF of your bill: ${url}\n\nThank you`,
    )
    expect(billMessage(bill, shop)).not.toContain('PDF')
  })

  it('sends a short caption with the PDF', () => {
    expect(billCaption(bill, shop)).toBe(
      'Bill INV-2026-0007 from Sharma Hardware: ₹880, ₹380 due. Thank you!',
    )
    expect(billCaption({ ...bill, due_amount: 0 }, shop)).toBe(
      'Bill INV-2026-0007 from Sharma Hardware: ₹880, paid in full. Thank you!',
    )
  })

  it('leaves out the paid line when nothing is paid', () => {
    const message = billMessage({ ...bill, paid_amount: 0, due_amount: 880, payments: [] }, shop)

    expect(message).not.toContain('Paid')
    expect(message).toContain('*Due: ₹880*')
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
    expect(message).toContain('₹500 is due on your account')
    expect(message).toContain('• INV-2026-0007 of 2 Nov 2026: ₹200')
    expect(message.indexOf('INV-2026-0007')).toBeLessThan(message.indexOf('INV-2026-0009'))
    expect(message).not.toContain('INV-2026-0008')
  })

  it('confirms a payment on one bill with what that bill still owes', () => {
    const url = 'https://shop.example/bill/Ab3dEf6hIj9kLm2nOp5qRs8tUv1wXy4zAb7cDe0f'

    const message = billPaymentReceiptMessage(
      { ...bill, due_amount: 80, share_url: url },
      { method: 'bank_transfer', amount: 300 },
      shop,
    )

    expect(message).toContain('Received ₹300 by Bank transfer from Ramesh Kumar on ')
    expect(message).toContain('for bill INV-2026-0007. Thank you!')
    expect(message).toContain('Still due on this bill: ₹80')
    expect(message).toContain(`PDF of your bill: ${url}`)
    expect(
      billPaymentReceiptMessage({ ...bill, due_amount: 0 }, { method: 'cash', amount: 80 }, shop),
    ).toContain('This bill is now paid in full.')
  })

  it('confirms a payment with what is still due', () => {
    const customer = { name: 'Ramesh Kumar', total_due: 100 }

    const message = paymentReceiptMessage(customer, { method: 'upi', amount: 400 }, shop)

    expect(message).toContain('Received ₹400 by UPI from Ramesh Kumar')
    expect(message).toContain('Still due: ₹100')
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

describe('changing a saved bill', () => {
  const saved = {
    total: 880,
    paid_amount: 700,
    refunded_amount: 100,
    returned_amount: 0,
    due_amount: 280,
    items: [],
  }
  const item = {
    id: 31,
    product_variant_id: 70,
    product_name: 'Wire Nails',
    variant_name: '2 inch',
    unit: 'kg',
    quantity: 1.5,
    returned_quantity: 0.5,
    price: 120,
    list_price: 120,
    line_total: 180,
    current: { stock_quantity: 18.5, allow_decimal: true, unit_name: 'Kilogram' },
  }

  it('puts a saved line in the form, counting its own items as in stock', () => {
    const line = editLine(item)

    expect(line).toMatchObject({
      id: 31,
      variant_id: 70,
      unit: { short_name: 'kg', name: 'Kilogram', allow_decimal: true },
      stock: 20,
      quantity: 1.5,
      price: 120,
      original: { quantity: 1.5, price: 120 },
    })
    expect(editLine({ ...item, current: null }).stock).toBeNull()
    expect(isShortOfStock(editLine({ ...item, current: null }))).toBe(false)
  })

  it('sends saved lines with their id and new ones without', () => {
    const lines = [
      { ...editLine(item), quantity: '2' },
      { ...billLine(nails, nails.variants[0]), variant_id: 71, price: 130 },
    ]

    expect(
      editPayload({ name: 'Ramesh', mobile: '9876543210', address: '' }, lines, {
        method: 'cash',
        reference: 'x',
      }),
    ).toEqual({
      customer: { name: 'Ramesh', mobile: '9876543210', address: null },
      items: [
        { id: 31, product_variant_id: 70, quantity: 2, price: 120 },
        { product_variant_id: 71, quantity: 1, price: 130 },
      ],
      refund: { method: 'cash', reference: null },
    })
    expect(editPayload({ name: 'R', mobile: '9876543210', address: '' }, lines).refund).toBeNull()
  })

  it('works out what has to be given back when the total drops below what was paid', () => {
    expect(netPaid(saved)).toBe(600)
    expect(refundAfterEdit(saved, 880)).toBe(0)
    expect(refundAfterEdit(saved, 450)).toBe(150)
    expect(refundAfterEdit({ ...saved, returned_amount: 100 }, 650)).toBe(50)
  })
})

describe('returns and cancelling', () => {
  const item = { id: 31, quantity: 3, returned_quantity: 1, price: 350 }

  it('knows how much of a line can still come back', () => {
    expect(returnableQuantity(item)).toBe(2)
    expect(returnableQuantity({ quantity: 1.5, returned_quantity: 1.2 })).toBe(0.3)
  })

  it('takes a return off the due first and gives back the rest', () => {
    const rows = [{ ...returnRow(item), quantity: '2' }]

    expect(returnTotal(rows)).toBe(700)
    expect(returnSettlement({ due_amount: 450 }, 700)).toEqual({ dueReduced: 450, refund: 250 })
    expect(returnSettlement({ due_amount: 900 }, 700)).toEqual({ dueReduced: 700, refund: 0 })
  })

  it('sends only the lines coming back', () => {
    const rows = [
      { ...returnRow(item), quantity: '1' },
      { ...returnRow({ ...item, id: 32 }), quantity: '' },
    ]

    expect(returnPayload(rows, ' Wrong size ', { method: 'upi', reference: ' R1 ' })).toEqual({
      items: [{ bill_item_id: 31, quantity: 1 }],
      note: 'Wrong size',
      refund: { method: 'upi', reference: 'R1' },
    })
    expect(returnPayload(rows, '', null).refund).toBeNull()
    expect(cancelPayload('  Made twice ', { method: 'cash', reference: 'x' })).toEqual({
      reason: 'Made twice',
      refund: { method: 'cash', reference: null },
    })
  })

  it('shows returned and cancelled bills, and says so on WhatsApp', () => {
    expect(statusClass({ value: 'returned' })).toBe('badge-muted')
    expect(statusClass({ value: 'cancelled' })).toBe('badge-muted')

    const shop = { name: 'Sharma Hardware', address: null, phone: null }
    const bill = {
      bill_number: 'INV-2026-0007',
      created_at: '2026-11-02T10:15:00Z',
      customer: { name: 'Ramesh Kumar', mobile: '9876543210' },
      status: { value: 'cancelled', label: 'Cancelled' },
      total: 700,
      returned_amount: 350,
      paid_amount: 700,
      refunded_amount: 700,
      due_amount: 0,
      items: [],
      payments: [{ method: { value: 'cash', label: 'Cash' }, amount: 700 }],
    }

    const message = billMessage(bill, shop)

    expect(message).toContain('*This bill has been cancelled.*')
    expect(message).toContain('Returned: −₹350')
    expect(message).toContain('Given back: ₹700')
  })
})
