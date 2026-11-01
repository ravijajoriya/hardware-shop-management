import { describe, it, expect } from 'vitest'

import { formatMoney, formatQuantity } from '../format'

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
