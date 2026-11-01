import { describe, it, expect } from 'vitest'

import {
  NEW_BRAND,
  imageChanges,
  imageOrder,
  productPayload,
  rowWorth,
  savedImageItem,
  variantRow,
} from '../productForm'

const form = {
  name: '  Claw Hammer ',
  slug: '',
  category_id: 3,
  brand_id: '',
  unit_id: 1,
  description: '  ',
  hsn_code: '8205',
  gst_rate: '',
  is_active: true,
}

describe('variantRow', () => {
  it('gives every row its own key', () => {
    expect(variantRow().key).not.toBe(variantRow().key)
  })

  it('fills a row from an existing variant', () => {
    const row = variantRow({ id: 9, name: '500 g', sku: null, purchase_price: 250, mrp: null })

    expect(row).toMatchObject({ id: 9, name: '500 g', sku: '', purchase_price: 250, mrp: '' })
  })
})

describe('productPayload', () => {
  it('trims text, turns blanks into null and numbers into numbers', () => {
    const rows = [
      {
        ...variantRow(),
        name: ' ',
        purchase_price: '250',
        selling_price: '320.50',
        mrp: '',
        stock_quantity: '4',
      },
    ]

    expect(productPayload(form, rows)).toEqual({
      name: 'Claw Hammer',
      slug: null,
      category_id: 3,
      brand_id: null,
      new_brand_name: null,
      unit_id: 1,
      description: null,
      hsn_code: '8205',
      gst_rate: null,
      is_active: true,
      variants: [
        {
          name: null,
          sku: null,
          purchase_price: 250,
          selling_price: 320.5,
          mrp: null,
          stock_quantity: 4,
        },
      ],
    })
  })

  it('sends a typed brand name instead of a brand id when adding a brand', () => {
    const payload = productPayload(
      { ...form, brand_id: NEW_BRAND, new_brand_name: '  Asian Paints ' },
      [variantRow()],
    )

    expect(payload.brand_id).toBeNull()
    expect(payload.new_brand_name).toBe('Asian Paints')
  })

  it('ignores a typed brand name once a listed brand is picked', () => {
    const payload = productPayload({ ...form, brand_id: 4, new_brand_name: 'Asian Paints' }, [
      variantRow(),
    ])

    expect(payload.brand_id).toBe(4)
    expect(payload.new_brand_name).toBeNull()
  })

  it('sends the id only for variants that already exist', () => {
    const rows = [variantRow({ id: 7, name: '1 L' }), variantRow({ name: '4 L' })]

    const [existing, added] = productPayload(form, rows).variants

    expect(existing.id).toBe(7)
    expect(added).not.toHaveProperty('id')
  })
})

describe('image changes', () => {
  const saved = [
    { id: 1, url: 'front.jpg' },
    { id: 2, url: 'side.jpg' },
    { id: 3, url: 'back.jpg' },
  ]
  const newFile = (name) => ({ key: name, id: null, url: `blob:${name}`, file: { name } })

  it('lists removed images and new files', () => {
    // Side removed, back made the main image, two files added.
    const items = [savedImageItem(saved[2]), newFile('a'), savedImageItem(saved[0]), newFile('b')]

    const changes = imageChanges(saved, items)

    expect(changes.deleteIds).toEqual([2])
    expect(changes.files).toEqual([{ name: 'a' }, { name: 'b' }])
  })

  it('places uploaded files where they were shown', () => {
    const items = [savedImageItem(saved[2]), newFile('a'), savedImageItem(saved[0]), newFile('b')]

    expect(imageOrder(items, [10, 11])).toEqual([3, 10, 1, 11])
  })

  it('needs nothing when the images did not change', () => {
    const items = saved.map(savedImageItem)

    expect(imageChanges(saved, items)).toEqual({ deleteIds: [], files: [] })
    expect(imageOrder(items, [])).toEqual([1, 2, 3])
  })
})

describe('rowWorth', () => {
  it('values the stock at purchase and selling price', () => {
    expect(rowWorth({ stock_quantity: '10', purchase_price: '300', selling_price: 360 })).toEqual({
      stock: 10,
      cost: 3000,
      selling: 3600,
    })
  })

  it('counts unfilled numbers as zero', () => {
    expect(rowWorth({ stock_quantity: '', purchase_price: '', selling_price: '' })).toEqual({
      stock: 0,
      cost: 0,
      selling: 0,
    })
  })
})
