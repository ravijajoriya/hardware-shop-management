import { toPaise } from '@/utils/billing'
import { isoDate } from '@/utils/stock'

// Ready-made periods for the reports, by this device's calendar.
export const PERIODS = [
  { value: 'today', label: 'Today' },
  { value: 'yesterday', label: 'Yesterday' },
  { value: 'this_week', label: 'This week' },
  { value: 'this_month', label: 'This month' },
  { value: 'last_month', label: 'Last month' },
  { value: 'this_year', label: 'This year' },
]

function day(year, month, date) {
  return isoDate(new Date(year, month, date))
}

// The first and last day of a ready-made period. A week starts on Monday.
export function periodRange(period, today = new Date()) {
  const year = today.getFullYear()
  const month = today.getMonth()
  const date = today.getDate()

  switch (period) {
    case 'yesterday':
      return { from: day(year, month, date - 1), to: day(year, month, date - 1) }
    case 'this_week': {
      const sinceMonday = (today.getDay() + 6) % 7

      return { from: day(year, month, date - sinceMonday), to: isoDate(today) }
    }
    case 'this_month':
      return { from: day(year, month, 1), to: isoDate(today) }
    case 'last_month':
      // Day 0 of this month is the last day of the one before.
      return { from: day(year, month - 1, 1), to: day(year, month, 0) }
    case 'this_year':
      return { from: day(year, 0, 1), to: isoDate(today) }
    default:
      return { from: isoDate(today), to: isoDate(today) }
  }
}

// The money that stayed with the shop, by method: what came in less what was given back. Online
// is UPI and bank together, so cash and online add up to the total.
export function moneyKept(received, refunded) {
  const kept = (method) => toPaise(received[method]) - toPaise(refunded[method])

  return {
    cash: kept('cash') / 100,
    upi: kept('upi') / 100,
    bank_transfer: kept('bank_transfer') / 100,
    online: (kept('upi') + kept('bank_transfer')) / 100,
    total: kept('total') / 100,
  }
}

// Which ready-made period these days are, or "custom".
export function periodOf(from, to, today = new Date()) {
  return (
    PERIODS.find((period) => {
      const range = periodRange(period.value, today)

      return range.from === from && range.to === to
    })?.value ?? 'custom'
  )
}
