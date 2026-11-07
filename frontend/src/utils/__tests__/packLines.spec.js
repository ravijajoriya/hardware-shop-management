import { describe, expect, it } from 'vitest'

import { billLine, billPayload, editLine, isShortOfStock, sellAs } from '../billing'
import { costAfter, enterAs, entryPayload, lineCost, stockLine } from '../stock'
import {
  productPayload,
  rowWorth,
  rowsResized,
  rowsToLoose,
  rowsToPacks,
  variantRow,
} from '../productForm'

// 1 mm wire: bought in 90 m coils, sold at ₹18 a metre or ₹1,350 a coil, costing ₹1,000 a coil.
const metre = { id: 1, name: 'Metre', short_name: 'm', allow_decimal: true }
const coil = { unit: { id: 2, name: 'Coil', short_name: 'coil' }, size: 90 }
const wire = { id: 5, name: 'Copper Wire', unit: metre, pack: coil }
const thin = {
  id: 50,
  name: '1 mm',
  selling_price: 18,
  pack_price: 1350,
  purchase_price: 11.1111,
  stock_quantity: 535,
}

describe('selling loose or by the pack', () => {
  it('starts loose and switches to the coil in one go, with its price', () => {
    const line = billLine(wire, thin)

    expect(line).toMatchObject({ sold_as: 'loose', unit: metre, unit_size: 1, price: 18 })

    line.quantity = 5
    sellAs(line, 'pack')

    expect(line).toMatchObject({
      sold_as: 'pack',
      unit: { name: 'Coil', short_name: 'coil', allow_decimal: false },
      unit_size: 90,
      list_price: 1350,
      price: 1350,
      quantity: 1,
    })

    sellAs(line, 'loose')
    expect(line).toMatchObject({ sold_as: 'loose', unit: metre, price: 18, quantity: 1 })
  })

  it('compares coils with the stock in metres', () => {
    const line = billLine(wire, thin)
    sellAs(line, 'pack')

    line.quantity = 5
    expect(isShortOfStock(line)).toBe(false) // 450 m of 535 m

    line.quantity = 6
    expect(isShortOfStock(line)).toBe(true) // 540 m
  })

  it('tells the API how each line is sold', () => {
    const coilLine = billLine(wire, thin)
    sellAs(coilLine, 'pack')

    expect(billPayload({ name: 'Ramesh', mobile: '9876543210' }, [coilLine], []).items).toEqual([
      { product_variant_id: 50, sold_as: 'pack', quantity: 1, price: 1350 },
    ])
  })

  it('keeps a saved coil line in coils, with its own coils counted as in stock', () => {
    const line = editLine({
      id: 3,
      product_variant_id: 50,
      product_name: 'Copper Wire',
      variant_name: '1 mm',
      unit: 'coil',
      unit_size: 90,
      base_unit: 'm',
      sold_as: 'pack',
      quantity: 2,
      price: 1350,
      list_price: 1350,
      current: { stock_quantity: 355, allow_decimal: false, unit_name: 'Coil', pack: coil },
    })

    expect(line).toMatchObject({
      sold_as: 'pack',
      unit: { short_name: 'coil', name: 'Coil', allow_decimal: false },
      unit_size: 90,
      loose_unit: { short_name: 'm' },
      pack: coil,
      stock: 535,
    })
  })
})

describe('stock coming in by the coil', () => {
  it('starts in coils at the coil’s cost, and can switch to metres', () => {
    const line = stockLine(wire, thin)

    expect(line).toMatchObject({ entered_as: 'pack', unit_size: 90, price: 1000 })
    expect(lineCost(line)).toBe(1000)

    enterAs(line, 'loose')
    expect(line).toMatchObject({ entered_as: 'loose', unit: metre, unit_size: 1, price: 11.11 })
  })

  it('works out the average cost per coil', () => {
    const line = stockLine(wire, { ...thin, stock_quantity: 450 })
    line.quantity = 5
    line.price = 1200

    // 5 coils at ₹1,000 and 5 at ₹1,200
    expect(costAfter(line)).toBe(1100)
  })

  it('tells the API how each line came in', () => {
    const line = stockLine(wire, thin)

    expect(entryPayload({ received_on: '2026-11-07' }, [line]).items).toEqual([
      { product_variant_id: 50, entered_as: 'pack', quantity: 1, purchase_price: 1000 },
    ])
  })
})

describe('the product form with a pack', () => {
  it('shows a saved size in coils: its cost, minimum and stock', () => {
    const row = variantRow({ ...thin, id: 50, min_stock: 180 }, coil)

    expect(row).toMatchObject({
      purchase_price: 1000,
      min_stock: 2,
      stock_packs: 5,
      stock_loose: 85,
    })
  })

  it('saves coil prices and counts in metres', () => {
    const row = { ...variantRow({}, coil), purchase_price: 1000, selling_price: 18 }
    Object.assign(row, { pack_price: '1350', min_stock: '2', stock_packs: '5', stock_loose: '85' })

    const payload = productPayload(
      { name: 'Copper Wire', brand_id: '', unit_id: 1, pack_unit_id: 2, location_area: 'shop' },
      [row],
      coil,
    )

    expect(payload).toMatchObject({ unit_id: 1, pack_unit_id: 2, pack_size: 90 })
    expect(payload.variants[0]).toMatchObject({
      purchase_price: 11.1111,
      selling_price: 18,
      pack_price: 1350,
      min_stock: 180,
      stock_quantity: 535,
    })
  })

  it('turns what is typed into coils when the pack is ticked, and back when unticked', () => {
    const rows = [{ ...variantRow({ purchase_price: 12, min_stock: 180 }), stock_quantity: 200 }]

    rowsToPacks(rows, 90)
    expect(rows[0]).toMatchObject({
      purchase_price: 1080,
      min_stock: 2,
      stock_packs: 2,
      stock_loose: 20,
    })

    rowsToLoose(rows, 90)
    expect(rows[0]).toMatchObject({ purchase_price: 12, min_stock: 180, stock_quantity: 200 })
  })

  it('keeps the cost per metre when the coil size changes', () => {
    const rows = [{ ...variantRow({}, coil), purchase_price: 1000, min_stock: 2, stock_packs: 1 }]

    rowsResized(rows, 90, 100)
    expect(rows[0]).toMatchObject({
      purchase_price: 1111.11,
      min_stock: 1.8,
      stock_packs: 0,
      stock_loose: 90,
    })
  })

  it('values the stock with the coil’s cost spread over its metres', () => {
    const row = { ...variantRow({}, coil), purchase_price: 900, selling_price: 18 }
    Object.assign(row, { stock_packs: 2, stock_loose: 0 })

    expect(rowWorth(row, coil)).toEqual({ stock: 180, cost: 1800, selling: 3240 })
  })
})
