import { describe, it, expect } from 'vitest'

import { deviceName, subjectRoute } from '../activity'

describe('deviceName', () => {
  it.each([
    [
      'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
      'Chrome on Mac',
    ],
    [
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36 Edg/130.0',
      'Edge on Windows',
    ],
    [
      'Mozilla/5.0 (Linux; Android 14; SM-A546E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36',
      'Chrome on Android',
    ],
    [
      'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
      'Safari on iPhone',
    ],
    [
      'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/130.0 Mobile/15E148 Safari/604.1',
      'Chrome on iPhone',
    ],
    ['Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0', 'Firefox on Linux'],
  ])('reads %s', (userAgent, name) => {
    expect(deviceName(userAgent)).toBe(name)
  })

  it('says so when it cannot tell, and gives nothing when nothing was recorded', () => {
    expect(deviceName('curl/8.7.1')).toBe('Unknown device')
    expect(deviceName(null)).toBeNull()
  })
})

describe('subjectRoute', () => {
  it('opens the record an entry is about', () => {
    expect(subjectRoute({ type: 'bill', id: 9, exists: true })).toEqual({
      name: 'bill-show',
      params: { id: 9 },
    })
    expect(subjectRoute({ type: 'stock_entry', id: 4, exists: true })).toEqual({
      name: 'stock-entry-show',
      params: { id: 4 },
    })
    expect(subjectRoute({ type: 'brand', id: 2, exists: true })).toEqual({ name: 'brands' })
  })

  it('opens nothing once the record is deleted, or when there is no page for it', () => {
    expect(subjectRoute({ type: 'product', id: 5, exists: false })).toBeNull()
    expect(subjectRoute({ type: 'admin', id: 1, exists: true })).toBeNull()
    expect(subjectRoute(null)).toBeNull()
  })
})
