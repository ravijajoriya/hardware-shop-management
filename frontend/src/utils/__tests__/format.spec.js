import { describe, it, expect } from 'vitest'

import { formatDate, formatDateTime, formatMoney, formatQuantity, formatRupees } from '../format'

describe('formatRupees', () => {
  it('shows whole rupees only, for estimates like what the stock is worth', () => {
    expect(formatRupees(5944.44)).toBe('₹5,944')
    expect(formatRupees(9629.5)).toBe('₹9,630')
    expect(formatRupees(1234567.49)).toBe('₹12,34,567')
    expect(formatRupees(0)).toBe('₹0')
    expect(formatRupees(null)).toBe('₹0')
  })
})

describe('formatMoney', () => {
  it('formats rupees with Indian digit grouping', () => {
    expect(formatMoney(7400)).toBe('₹7,400')
    expect(formatMoney(1234567.5)).toBe('₹12,34,567.50')
  })

  it('shows paise only when there are some', () => {
    expect(formatMoney(1500)).toBe('₹1,500')
    expect(formatMoney('1500.00')).toBe('₹1,500')
    expect(formatMoney(320.5)).toBe('₹320.50')
    expect(formatMoney(0.05)).toBe('₹0.05')
    // Sums that pick up a float error still show as whole rupees or exact paise.
    expect(formatMoney(0.1 + 0.2)).toBe('₹0.30')
    expect(formatMoney(1499.9999999)).toBe('₹1,500')
  })

  it('treats a missing amount as zero', () => {
    expect(formatMoney(null)).toBe('₹0')
  })
})

describe('formatQuantity', () => {
  it('adds the unit and drops trailing zeros', () => {
    expect(formatQuantity(12.75, { short_name: 'kg' })).toBe('12.75 kg')
    expect(formatQuantity(3, { short_name: 'pc' })).toBe('3 pc')
    expect(formatQuantity(2.5)).toBe('2.5')
  })
})

describe('dates', () => {
  // A local time, so the result is the same in any time zone.
  const evening = new Date(2026, 10, 2, 15, 45)

  it('writes the day with a short month', () => {
    expect(formatDate(evening)).toBe('2 Nov 2026')
    expect(formatDateTime(evening)).toBe('2 Nov 2026, 3:45 pm')
  })

  it('leaves out a missing date', () => {
    expect(formatDate(null)).toBe('')
    expect(formatDateTime(undefined)).toBe('')
  })
})
