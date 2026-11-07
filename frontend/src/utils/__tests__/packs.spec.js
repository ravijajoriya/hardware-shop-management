import { describe, expect, it } from 'vitest'

import {
  costPerPack,
  costPerUnit,
  formatPacks,
  formatStock,
  fullPackPrice,
  joinStock,
  packLabel,
  packWord,
  splitStock,
} from '../packs'

const metre = { name: 'Metre', short_name: 'm' }
const coil = { unit: { name: 'Coil', short_name: 'coil' }, size: 90 }

describe('packWord', () => {
  it('names one pack or several, the way it is said', () => {
    expect(packWord({ name: 'Coil' })).toBe('coil')
    expect(packWord({ name: 'Coil' }, 5)).toBe('coils')
    expect(packWord({ name: 'Box' }, 2)).toBe('boxes')
    expect(packWord({ name: 'Bundle' }, 2)).toBe('bundles')
    expect(packWord({ name: 'Gross' }, 3)).toBe('gross')
    expect(formatPacks(1, { name: 'Bag' })).toBe('1 bag')
  })
})

describe('formatStock', () => {
  it('shows stock as it sits on the shelf: full packs and what is loose', () => {
    expect(formatStock(535, metre, coil)).toBe('5 coils + 85 m')
    expect(formatStock(540, metre, coil)).toBe('6 coils')
    expect(formatStock(40, metre, coil)).toBe('40 m')
    expect(formatStock(90, metre, coil)).toBe('1 coil')
  })

  it('is the plain quantity without a pack, or with none left', () => {
    expect(formatStock(12.5, metre)).toBe('12.5 m')
    expect(formatStock(0, metre, coil)).toBe('0 m')
    expect(formatStock(-5, metre, coil)).toBe('-5 m')
  })
})

describe('splitStock and joinStock', () => {
  it('turn loose units into full packs plus loose, and back', () => {
    expect(splitStock(535, 90)).toEqual({ packs: 5, loose: 85 })
    expect(splitStock(12.5, 0.5)).toEqual({ packs: 25, loose: 0 })
    expect(joinStock(5, 85, 90)).toBe(535)
    expect(joinStock('', '', 90)).toBe(0)
  })
})

describe('pack prices', () => {
  it('sells a full pack at its own price, or the loose price times the pack size', () => {
    expect(fullPackPrice({ selling_price: 18, pack_price: 1350 }, coil)).toBe(1350)
    expect(fullPackPrice({ selling_price: 18, pack_price: null }, coil)).toBe(1620)
    expect(fullPackPrice({ selling_price: 18, pack_price: '' }, coil)).toBe(1620)
  })

  it('turns a coil’s cost into a cost per metre that divides back exactly', () => {
    expect(costPerUnit(1000, 90)).toBe(11.1111)
    expect(costPerPack(11.1111, 90)).toBe(1000)
    expect(packLabel(coil, metre)).toBe('1 coil = 90 m')
  })
})
