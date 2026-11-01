import { describe, it, expect } from 'vitest'

import { floorName, locationCode, locationLabel } from '../location'

describe('locationCode', () => {
  // The same examples as the backend's ProductLocationServiceTest, so the preview matches what is saved.
  it.each([
    [{ area: 'shop' }, 'SHOP'],
    [{ area: 'godown', floor: 0 }, 'GODOWN-GF'],
    [{ area: 'shop', floor: 0, rack: '3', shelf: 2 }, 'SHOP-GF-R3-S2'],
    [{ area: 'godown', floor: 1, rack: '12', shelf: 4 }, 'GODOWN-1F-R12-S4'],
    [{ area: 'shop', floor: 2, rack: 'a' }, 'SHOP-2F-RA'],
  ])('builds %o as %s', (place, code) => {
    expect(locationCode(place)).toBe(code)
  })

  it('treats empty form fields as not set', () => {
    expect(locationCode({ area: 'shop', floor: '', rack: '', shelf: '' })).toBe('SHOP')
    expect(locationCode({ area: 'shop', floor: '0', rack: ' b ', shelf: '7' })).toBe(
      'SHOP-GF-RB-S7',
    )
  })
})

describe('locationLabel', () => {
  it('says the same place in words', () => {
    expect(locationLabel({ area: 'shop', floor: 0, rack: '3', shelf: 2 })).toBe(
      'Shop · Ground floor · Rack 3 · Shelf 2',
    )
    expect(locationLabel({ area: 'godown', floor: 1, rack: null, shelf: null })).toBe(
      'Godown · 1st floor',
    )
  })
})

describe('floorName', () => {
  it('names floors the way people say them', () => {
    expect([0, 1, 2, 3, 4, 11, 12, 13, 21].map(floorName)).toEqual([
      'Ground floor',
      '1st floor',
      '2nd floor',
      '3rd floor',
      '4th floor',
      '11th floor',
      '12th floor',
      '13th floor',
      '21st floor',
    ])
  })
})
