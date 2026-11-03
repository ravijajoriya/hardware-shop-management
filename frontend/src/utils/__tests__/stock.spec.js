import { describe, it, expect } from 'vitest'

import {
  correctionPayload,
  costAfter,
  entryPayload,
  entryTotal,
  isoDate,
  stockChange,
  stockLine,
} from '../stock'

const pc = { id: 3, name: 'Piece', short_name: 'pc', allow_decimal: false }
const hammer = {
  id: 5,
  name: 'Claw Hammer',
  unit: pc,
  variants: [{ id: 50, name: null, purchase_price: 280, selling_price: 350, stock_quantity: 10 }],
}

describe('stockLine', () => {
  it('starts at one, at what the size cost last time', () => {
    const line = stockLine(hammer, hammer.variants[0])

    expect(line).toMatchObject({
      variant_id: 50,
      product_name: 'Claw Hammer',
      unit: pc,
      stock: 10,
      cost: 280,
      quantity: 1,
      price: 280,
    })
    expect(stockLine(hammer, hammer.variants[0]).key).not.toBe(line.key)
  })
})

describe('costAfter', () => {
  const line = { ...stockLine(hammer, hammer.variants[0]), quantity: 20, price: 310 }

  it('averages the new stock with the stock already there', () => {
    expect(costAfter(line)).toBe(300)
    expect(costAfter({ ...line, stock: 2.5, cost: 90, quantity: '1.5', price: '100' })).toBe(93.75)
  })

  it('takes the new price when there is no stock to average with', () => {
    expect(costAfter({ ...line, stock: 0 })).toBe(310)
    expect(costAfter({ ...line, stock: -2 })).toBe(310)
    expect(costAfter({ ...line, cost: null })).toBe(310)
  })

  it('keeps the cost while no quantity is entered', () => {
    expect(costAfter({ ...line, quantity: '' })).toBe(280)
  })
})

describe('entry totals and payload', () => {
  it('adds up the delivery in paise', () => {
    expect(
      entryTotal([
        { quantity: 20, price: 310 },
        { quantity: '1.5', price: '100' },
        { quantity: 3, price: 0.1 },
      ]),
    ).toBe(6350.3)
  })

  it('sends the delivery as the API expects', () => {
    const lines = [{ ...stockLine(hammer, hammer.variants[0]), quantity: '20', price: '310.50' }]

    expect(
      entryPayload(
        {
          supplier_name: ' Sharma Traders ',
          supplier_bill_number: '',
          received_on: '2026-11-03',
          note: '  ',
        },
        lines,
      ),
    ).toEqual({
      supplier_name: 'Sharma Traders',
      supplier_bill_number: null,
      received_on: '2026-11-03',
      note: null,
      items: [{ product_variant_id: 50, quantity: 20, purchase_price: 310.5 }],
    })
  })

  it('writes dates by the device’s calendar', () => {
    expect(isoDate(new Date(2026, 10, 3, 23, 59))).toBe('2026-11-03')
    expect(isoDate(new Date(2026, 0, 5))).toBe('2026-01-05')
  })
})

describe('corrections', () => {
  it('sends the new count and why', () => {
    expect(correctionPayload({ quantity: '8', reason: 'damaged', note: ' Cracked ' })).toEqual({
      quantity: 8,
      reason: 'damaged',
      note: 'Cracked',
    })
  })

  it('shows a change in stock with its sign', () => {
    expect(stockChange(20, 'pc')).toBe('+20 pc')
    expect(stockChange(-2.5, 'kg')).toBe('−2.5 kg')
  })
})
