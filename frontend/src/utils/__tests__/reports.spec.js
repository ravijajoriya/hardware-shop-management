import { describe, it, expect } from 'vitest'

import { moneyKept, periodOf, periodRange } from '../reports'

// Wednesday 12 November 2026.
const today = new Date(2026, 10, 12, 15, 30)

describe('periodRange', () => {
  it.each([
    ['today', '2026-11-12', '2026-11-12'],
    ['yesterday', '2026-11-11', '2026-11-11'],
    ['this_week', '2026-11-09', '2026-11-12'],
    ['this_month', '2026-11-01', '2026-11-12'],
    ['last_month', '2026-10-01', '2026-10-31'],
    ['this_year', '2026-01-01', '2026-11-12'],
  ])('%s runs from %s to %s', (period, from, to) => {
    expect(periodRange(period, today)).toEqual({ from, to })
  })

  it('starts the week on Monday, even on a Sunday', () => {
    expect(periodRange('this_week', new Date(2026, 10, 15))).toEqual({
      from: '2026-11-09',
      to: '2026-11-15',
    })
  })

  it('handles the turn of the year', () => {
    const newYear = new Date(2027, 0, 1)

    expect(periodRange('yesterday', newYear)).toEqual({ from: '2026-12-31', to: '2026-12-31' })
    expect(periodRange('last_month', newYear)).toEqual({ from: '2026-12-01', to: '2026-12-31' })
  })
})

describe('periodOf', () => {
  it('names a ready-made period, or calls it custom', () => {
    expect(periodOf('2026-11-01', '2026-11-12', today)).toBe('this_month')
    expect(periodOf('2026-10-01', '2026-10-31', today)).toBe('last_month')
    expect(periodOf('2026-11-03', '2026-11-05', today)).toBe('custom')
  })
})

describe('moneyKept', () => {
  it('takes what was given back off what came in, method by method', () => {
    const received = { cash: 17840, upi: 4000.1, bank_transfer: 400.2, total: 22240.3 }
    const refunded = { cash: 590, upi: 250, bank_transfer: 0, total: 840 }

    expect(moneyKept(received, refunded)).toEqual({
      cash: 17250,
      upi: 3750.1,
      bank_transfer: 400.2,
      online: 4150.3,
      total: 21400.3,
    })
  })
})
