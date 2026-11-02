import { describe, it, expect } from 'vitest'

import { formatDate, formatDateTime, formatMoney, formatQuantity } from '../format'

describe('formatMoney', () => {
  it('formats rupees with Indian digit grouping', () => {
    expect(formatMoney(7400)).toBe('₹7,400.00')
    expect(formatMoney(1234567.5)).toBe('₹12,34,567.50')
  })

  it('treats a missing amount as zero', () => {
    expect(formatMoney(null)).toBe('₹0.00')
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
